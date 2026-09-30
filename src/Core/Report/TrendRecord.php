<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Cost\RunTimings;
use NightWorksIO\MutationGate\Core\Cost\Savings;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

use function sprintf;

/**
 * One entry of `trend.json`, as it is written from a verdict, read back from
 * the file, and handed on as the entry a verdict is compared with.
 *
 * @phpstan-type Entry array{
 *     commit: string,
 *     time: string,
 *     verdict?: string,
 *     score?: float,
 *     trees: array<string, float>,
 *     floors?: array<string, float>,
 *     runnerSeconds?: float,
 *     fullRunSeconds?: float,
 * }
 */
final readonly class TrendRecord
{
    /** @return Entry */
    public static function of(Verdict $verdict, Revision $commit, Instant $time): array
    {
        $score = Overview::of($verdict)->score();
        $trees = [];
        $floors = [];

        foreach ($verdict->trees() as $tree) {
            $path = $tree->tree()->path()->value();
            $treeScore = $tree->score();
            $floor = $tree->floor();
            $trees = $treeScore instanceof Score ? [...$trees, $path => $treeScore->percent()] : $trees;
            $floors = $floor instanceof Floor ? [...$floors, $path => $floor->percent()] : $floors;
        }

        $timings = $verdict->account()->timings();
        $savings = $verdict->account()->savings();

        return [
            'commit' => $commit->name(),
            'time' => $time->value(),
            'verdict' => $verdict->judgement()->value,
            ...$score instanceof Score ? ['score' => $score->percent()] : [],
            'trees' => $trees,
            'floors' => $floors,
            ...$timings instanceof RunTimings ? ['runnerSeconds' => $timings->spent()->runner()->seconds()] : [],
            ...$savings instanceof Savings ? ['fullRunSeconds' => $savings->fullRun()->seconds()] : [],
        ];
    }

    /** @param Entry $run */
    public static function entry(array $run): TrendEntry
    {
        $entry = TrendEntry::judged(
            array_key_exists('verdict', $run) ? Judgement::from($run['verdict']) : Unrecorded::floor(),
        );

        foreach ($run['trees'] as $path => $score) {
            $entry = $entry->withScore(Path::of($path), Score::ofHundredths(Percentage::hundredthsOf($score)));
        }

        foreach (array_key_exists('floors', $run) ? $run['floors'] : [] as $path => $floor) {
            $entry = $entry->withFloor(Path::of($path), Floor::of($floor));
        }

        return $entry;
    }

    /**
     * @return Entry
     *
     * @throws NotInShape
     */
    public static function read(Node $item): array
    {
        $score = $item->field('score');
        $verdict = $item->field('verdict');
        $floors = $item->field('floors');
        $runner = $item->field('runnerSeconds');
        $fullRun = $item->field('fullRunSeconds');

        return [
            'commit' => $item->field('commit')->text(),
            'time' => $item->field('time')->text(),
            ...$verdict->isPresent() ? ['verdict' => self::judgement($verdict)] : [],
            ...$score->isPresent() ? ['score' => $score->number()] : [],
            'trees' => self::percents($item->field('trees')),
            ...$floors->isPresent() ? ['floors' => self::percents($floors)] : [],
            ...$runner->isPresent() ? ['runnerSeconds' => $runner->number()] : [],
            ...$fullRun->isPresent() ? ['fullRunSeconds' => $fullRun->number()] : [],
        ];
    }

    /** @throws NotInShape */
    private static function judgement(Node $verdict): string
    {
        $judgement = Judgement::tryFrom($verdict->text());

        return $judgement instanceof Judgement
            ? $judgement->value
            : throw NotInShape::at($verdict->at(), 'a judgement');
    }

    /**
     * Each tree's percentage.
     *
     * @return array<string, float>
     *
     * @throws NotInShape
     */
    private static function percents(Node $trees): array
    {
        $percents = [];

        foreach ($trees->entries() as $path => $percent) {
            $percents[sprintf('%s', $path)] = Percentage::parse($percent->number()) instanceof Percentage
                ? $percent->number()
                : throw NotInShape::at($percent->at(), 'a percentage');
        }

        return $percents;
    }
}
