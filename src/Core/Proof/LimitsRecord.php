<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_map;

use NightWorksIO\MutationGate\Core\Cost\JobTimes;
use NightWorksIO\MutationGate\Core\Cost\ShardsAtOnce;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Runner\Multiple;

/**
 * What a ledger learned for the limits a config has it learn, as its
 * optional `kills`, `concurrency` and `jobTimes` sections hold it (ADR-0073,
 * ADR-0196): each of the newest kills' time over its covering tests' own
 * time, each of the newest runs' most shards at once, and what each of the
 * newest jobs measured of its own time (see JobTimesRecord), oldest first.
 *
 * Reading keeps each positive number and drops anything else, never
 * repairing it; a ledger without the sections learned nothing, which has a
 * learned limit take its standard, never a verdict, so the ledger's format
 * does not count them.
 *
 * @internal the shape of the `kills`, `concurrency` and `jobTimes` sections of the ledger file
 *
 * @phpstan-import-type Written from JobTimesRecord as JobWritten
 */
final readonly class LimitsRecord
{
    public const string KILLS = 'kills';

    public const string CONCURRENCY = 'concurrency';

    public const string JOBS = 'jobTimes';

    /**
     * The sections a ledger writes of what it learned for the limits, each
     * only where it learned some of it.
     *
     * @return array<string, list<float>|list<int>|list<JobWritten>>
     */
    public static function of(LimitHistory $history): array
    {
        $kills = array_map(static fn(Multiple $kill): float => $kill->times(), [...$history->kills()]);
        $runs = array_map(static fn(ShardsAtOnce $run): int => $run->count(), [...$history->concurrency()]);
        $jobs = array_map(JobTimesRecord::of(...), [...$history->jobs()]);

        return [
            ...$kills === [] ? [] : [self::KILLS => $kills],
            ...$runs === [] ? [] : [self::CONCURRENCY => $runs],
            ...$jobs === [] ? [] : [self::JOBS => $jobs],
        ];
    }

    /** What a ledger's sections hold; nothing of any it holds none of. */
    public static function read(Node $kills, Node $concurrency, Node $jobs): LimitHistory
    {
        $ratios = [];
        $runs = [];

        foreach (self::itemsOf($kills) as $kill) {
            $ratios = match ($kill->kind()) {
                Kind::Integer => [...$ratios, Multiple::of($kill->integer())],
                Kind::Number => [...$ratios, Multiple::of($kill->number())],
                Kind::Map, Kind::List, Kind::Empty, Kind::Text, Kind::Boolean, Kind::Null, Kind::Nothing => $ratios,
            };
        }

        foreach (self::itemsOf($concurrency) as $run) {
            $runs = $run->kind() === Kind::Integer ? [...$runs, ShardsAtOnce::of($run->integer())] : $runs;
        }

        $paces = [];

        foreach (self::itemsOf($jobs) as $job) {
            $paces = [...$paces, ...self::paceIn($job)];
        }

        return LimitHistory::none()->afterKills(...$ratios)->afterRuns(...$runs)->afterJobs(...$paces);
    }

    /**
     * The job an item holds; none where it holds none.
     *
     * @return list<JobTimes>
     */
    private static function paceIn(Node $job): array
    {
        try {
            return $job->kind() === Kind::Map ? [JobTimesRecord::read($job)] : [];
        } catch (NotInShape) {
            return [];
        }
    }


    /** @return list<Node> */
    private static function itemsOf(Node $section): array
    {
        try {
            return $section->isPresent() ? $section->items() : [];
        } catch (NotInShape) {
            return [];
        }
    }
}
