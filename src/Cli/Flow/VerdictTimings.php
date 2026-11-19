<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use DateTimeImmutable;

use function min;

use NightWorksIO\MutationGate\Core\Ci\CiRun;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Cost\Phase;
use NightWorksIO\MutationGate\Core\Cost\RunTime;
use NightWorksIO\MutationGate\Core\Cost\RunTimings;
use NightWorksIO\MutationGate\Core\Cost\ShardTiming;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function round;
use function sprintf;

/**
 * A run's timings, as the verdict reads them from the shards' result files
 * and its own clock (ADR-0016): each shard whole, from its
 * start to the instant its result was written, with the steps its time went
 * to, and the verdict from when it began to now. On a CI, what the run took is
 * estimated, each job's setup taken from `shards.setup`: the wall time from
 * the first job's setup to now, and the runner time of every shard and the
 * verdict together, each with its setup. A run on no CI has no job to set up,
 * so its clock measures what it took. Where the CI's API said what the jobs
 * took, that is what the run took (ADR-0017).
 *
 * @internal
 */
final readonly class VerdictTimings
{
    public static function of(
        string $run,
        Results $results,
        DateTimeImmutable $began,
        DateTimeImmutable $now,
        Seconds|NotGiven $setup,
        RunTimes|NotGiven $measured = new NotGiven(),
    ): RunTimings {
        $each = $setup instanceof Seconds ? $setup->seconds() : 0.0;
        $verdict = Seconds::between($began, $now);
        $first = $began;
        $runner = $verdict->seconds() + $each;
        $shards = [];

        foreach ($results->shards() as [, $result]) {
            $spent = $result->measured()->spent();
            $start = self::start($result, $spent, $began);
            $first = min($first, $start);
            $runner += $spent->seconds() + $each;
            $shards[] = ShardTiming::of(
                $result->shard()->number(),
                Phase::of(Instant::at($start), $spent),
                $result->measured()->steps(),
            );
        }

        $wall = Seconds::of(Seconds::between($first, $now)->seconds() + $each);
        $spent = match (true) {
            $measured instanceof RunTimes => $measured->spent(),
            $setup instanceof Seconds => RunTime::estimated($wall, Seconds::of($runner)),
            default => RunTime::measured($wall, Seconds::of($runner)),
        };
        $plan = $measured instanceof RunTimes ? $measured->plan() : $measured;
        $timings = RunTimings::of($run, $spent);
        $timings = $plan instanceof Phase ? $timings->withPlan($plan) : $timings;

        foreach ($shards as $shard) {
            $timings = $timings->withShard($shard);
        }

        return $timings->withVerdict(Phase::of(Instant::at($began), $verdict));
    }

    /** Each job's setup on a CI, `shards.setup`; none on a run no CI started. */
    public static function setupOn(Variables $environment, Seconds $setup): Seconds|NotGiven
    {
        return CiRun::read($environment) instanceof CiRun ? $setup : NotGiven::value();
    }

    /**
     * When a shard began: the instant its result was written, less what it
     * spent; the verdict's own where that cannot be read.
     */
    private static function start(ShardResult $result, Seconds $spent, DateTimeImmutable $began): DateTimeImmutable
    {
        $at = $result->measured()->at()->moment();

        return $at instanceof DateTimeImmutable
            ? $at->modify(sprintf('-%d seconds', round($spent->seconds())))
            : $began;
    }
}
