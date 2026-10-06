<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use DateTimeImmutable;
use DateTimeZone;
use NightWorksIO\MutationGate\Core\Cost\Phase;
use NightWorksIO\MutationGate\Core\Cost\RunTime;
use NightWorksIO\MutationGate\Core\Cost\RunTimings;
use NightWorksIO\MutationGate\Core\Cost\ShardTiming;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function round;
use function sprintf;

/**
 * A run's timings, as the verdict reads them from the shards' result files
 * and its own clock (ADR-0016, decision 19): each shard whole, from its
 * start to the instant its result was written, with the steps its time went
 * to, and the verdict from when it began to now. What the run took is
 * estimated, each job's setup taken from `shards.setup`: the wall time from
 * the first job's setup to now, and the runner time of every shard and the
 * verdict together, each with its setup.
 */
final readonly class VerdictTimings
{
    public static function of(
        string $run,
        Results $results,
        DateTimeImmutable $began,
        DateTimeImmutable $now,
        Seconds $setup,
    ): RunTimings {
        $verdict = Seconds::between($began, $now);
        $first = $began;
        $runner = $verdict->seconds() + $setup->seconds();
        $shards = [];

        foreach ($results->shards() as [, $result]) {
            $spent = $result->measured()->spent();
            $start = self::start($result, $spent, $began);
            $first = $start < $first ? $start : $first;
            $runner += $spent->seconds() + $setup->seconds();
            $shards[] = ShardTiming::of(
                $result->shard()->number(),
                Phase::of(Instant::at($start), $spent),
                $result->measured()->steps(),
            );
        }

        $wall = Seconds::of(Seconds::between($first, $now)->seconds() + $setup->seconds());
        $timings = RunTimings::of($run, RunTime::estimated($wall, Seconds::of($runner)));

        foreach ($shards as $shard) {
            $timings = $timings->withShard($shard);
        }

        return $timings->withVerdict(Phase::of(Instant::at($began), $verdict));
    }

    /**
     * When a shard began: the instant its result was written, less what it
     * spent; the verdict's own where that cannot be read.
     */
    private static function start(ShardResult $result, Seconds $spent, DateTimeImmutable $began): DateTimeImmutable
    {
        $written = $result->measured()->at()->value();
        $at = DateTimeImmutable::createFromFormat(Instant::FORMAT, $written, new DateTimeZone(Instant::UTC));

        return $at instanceof DateTimeImmutable
            ? $at->modify(sprintf('-%d seconds', (int) round($spent->seconds())))
            : $began;
    }
}
