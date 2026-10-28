<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Dashboard;

use function array_map;
use function in_array;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\NoHistory;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Report\Summary;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;

use function sprintf;

/**
 * A repository's `summary.json` as a dashboard reads it (ADR-0024): the
 * commit and time of its latest verdict on the default branch, the verdict,
 * the project's score, each tree, the floor for new code, and what the gate
 * saved over the last 30 days. Whatever the file holds is the repository's
 * word, so a file not in the shape of `"format": 1` is refused whole.
 */
final readonly class Published
{
    private const string UNREAD = 'Its summary.json is not one this gate reads: %s';

    private const string FORMAT = 'Its summary.json is format %s, and this gate reads format %d.';

    /** @param list<PublishedTree> $trees */
    private function __construct(
        private string $commit,
        private Instant $time,
        private Judgement $verdict,
        private Score|NothingToMutate $score,
        private array $trees,
        private Floor $newCode,
        private Seconds|NoHistory $saved,
    ) {
    }

    /** What a `summary.json` says; or why it says nothing this gate reads. */
    public static function read(string $json): self|CannotJudge
    {
        try {
            return self::readFrom(Node::decode($json, Summary::FILE));
        } catch (NotInShape $misread) {
            return CannotJudge::because(sprintf(self::UNREAD, $misread->getMessage()));
        }
    }

    public function commit(): string
    {
        return $this->commit;
    }

    public function time(): Instant
    {
        return $this->time;
    }

    public function verdict(): Judgement
    {
        return $this->verdict;
    }

    public function score(): Score|NothingToMutate
    {
        return $this->score;
    }

    /** @return list<PublishedTree> */
    public function trees(): array
    {
        return $this->trees;
    }

    /** @return list<TreeBelow> each tree that scores below its floor, in order */
    public function belowFloor(): array
    {
        $below = [];

        foreach ($this->trees as $tree) {
            $under = $tree->below();
            $below = $under instanceof TreeBelow ? [...$below, $under] : $below;
        }

        return $below;
    }

    public function newCode(): Floor
    {
        return $this->newCode;
    }

    /** What the gate saved over the last 30 days; no history where the repository knew of none. */
    public function saved(): Seconds|NoHistory
    {
        return $this->saved;
    }

    /** @throws NotInShape */
    private static function readFrom(Node $summary): self|CannotJudge
    {
        $format = $summary->field('format');

        if ($format->integer() !== Summary::FORMAT) {
            return CannotJudge::because(sprintf(self::FORMAT, $format->json(), Summary::FORMAT));
        }

        $score = $summary->field('score');
        $savings = $summary->field('savings');

        return new self(
            $summary->field('commit')->text(),
            self::instant($summary->field('time')),
            self::judgementIn($summary->field('verdict')),
            $score->isPresent() ? Score::ofHundredths(self::hundredths($score)) : NothingToMutate::found(),
            array_map(self::tree(...), $summary->field('trees')->items()),
            Floor::ofHundredths(self::hundredths($summary->field('newCode')->field('floor'))),
            $savings->isPresent() ? Seconds::of(self::seconds($savings->field('last30Days'))) : NoHistory::yet(),
        );
    }

    /** @throws NotInShape */
    private static function tree(Node $tree): PublishedTree
    {
        $floor = $tree->field('floor');
        $score = $tree->field('score');

        return PublishedTree::of(
            $tree->field('path')->text(),
            $floor->isPresent() ? Floor::ofHundredths(self::hundredths($floor)) : Undeclared::floor(),
            $score->isPresent() ? Score::ofHundredths(self::hundredths($score)) : NothingToMutate::found(),
        );
    }

    /** @throws NotInShape */
    private static function hundredths(Node $percent): int
    {
        $parsed = Percentage::parse($percent->number());

        return $parsed instanceof Percentage
            ? $parsed->hundredths()
            : throw NotInShape::at($percent->at(), 'a percentage from 0 to 100');
    }

    /** @throws NotInShape */
    private static function seconds(Node $seconds): float
    {
        $value = $seconds->number();

        return $value >= 0 ? $value : throw NotInShape::at($seconds->at(), 'a number of seconds');
    }

    /** @throws NotInShape */
    private static function instant(Node $time): Instant
    {
        $instant = Instant::parse($time->text());

        return $instant instanceof Instant ? $instant : throw NotInShape::at($time->at(), 'an instant in UTC');
    }

    /** @throws NotInShape */
    private static function judgementIn(Node $verdict): Judgement
    {
        $judgement = Judgement::tryFrom($verdict->text());

        $published = [Judgement::Passed, Judgement::Failed, Judgement::CannotJudge];

        return in_array($judgement, $published, strict: true)
            ? $judgement
            : throw NotInShape::at($verdict->at(), 'passed, failed or cannot-judge');
    }
}
