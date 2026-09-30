<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_key_exists;
use function array_slice;
use function count;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

/**
 * `trend.json`: one entry per verdict on the default branch, with its commit,
 * its time, the project's score and each tree's, keeping the newest 500. A
 * set with nothing to mutate has no score in its entry. Reading drops an
 * entry that is not in shape rather than repair it.
 */
final readonly class Trend
{
    private const int FORMAT = 1;

    /** How many entries it keeps, the newest. */
    private const int KEPT = 500;

    private const float HUNDREDTHS = 100.0;

    /**
     * @param list<array{commit: string, time: string, score?: float, trees: array<string, float>}> $runs oldest first
     */
    private function __construct(private array $runs)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** A trend as `trend.json` holds it; text that is not one reads as none. */
    public static function decode(string $json): self
    {
        try {
            $items = Node::decode($json)->field('runs')->items();
        } catch (NotInShape) {
            return self::none();
        }

        $runs = [];

        foreach ($items as $item) {
            try {
                $runs[] = self::read($item);
            } catch (NotInShape) {
                continue;
            }
        }

        return new self($runs);
    }

    /** This trend, and a verdict's entry, keeping the newest. */
    public function with(Verdict $verdict, string $commit, Instant $time): self
    {
        $score = Overview::of($verdict)->score();
        $trees = [];

        foreach ($verdict->trees() as $tree) {
            $treeScore = $tree->score();

            if ($treeScore instanceof Score) {
                $trees[$tree->tree()->path()->value()] = $treeScore->hundredths() / self::HUNDREDTHS;
            }
        }

        $run = [
            'commit' => $commit,
            'time' => $time->value(),
            ...$score instanceof Score ? ['score' => $score->hundredths() / self::HUNDREDTHS] : [],
            'trees' => $trees,
        ];
        $runs = [...$this->runs, $run];

        return new self(array_slice($runs, count($runs) > self::KEPT ? count($runs) - self::KEPT : 0));
    }

    public function json(): string
    {
        return Json::encode(['format' => self::FORMAT, 'runs' => $this->runs]);
    }

    /**
     * The project's score of every entry that has one, oldest first.
     *
     * @return list<float>
     */
    public function scores(): array
    {
        $scores = [];

        foreach ($this->runs as $run) {
            $scores = array_key_exists('score', $run) ? [...$scores, $run['score']] : $scores;
        }

        return $scores;
    }

    /**
     * @return array{commit: string, time: string, score?: float, trees: array<string, float>}
     *
     * @throws NotInShape
     */
    private static function read(Node $item): array
    {
        $trees = [];

        foreach ($item->field('trees')->entries() as $path => $score) {
            $trees[$path] = $score->number();
        }

        $score = $item->field('score');

        return [
            'commit' => $item->field('commit')->text(),
            'time' => $item->field('time')->text(),
            ...$score->isPresent() ? ['score' => $score->number()] : [],
            'trees' => $trees,
        ];
    }
}
