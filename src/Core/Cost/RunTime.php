<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use function ceil;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * What a run takes: the wall time from its first job's start to its last
 * job's end, and the runner time every job spent together, measured where
 * the CI tells or no CI job was set up, and estimated from `shards.setup`
 * where a CI does not tell (ADR-0016, and ADR-0017); and on a CI, the
 * minutes it bills, each job's time rounded up to a whole minute (ADR-0240).
 */
final readonly class RunTime
{
    private function __construct(
        private Seconds $wall,
        private Seconds $runner,
        private bool $measured,
        private int|NotGiven $billed = new NotGiven(),
    ) {
    }

    /** A run time the CI's own job times gave, or the gate's clock on a run with no CI job to set up. */
    public static function measured(Seconds $wall, Seconds $runner): self
    {
        return new self($wall, $runner, measured: true);
    }

    /** A run time from the gate's own timings, with each job's setup taken from `shards.setup`. */
    public static function estimated(Seconds $wall, Seconds $runner): self
    {
        return new self($wall, $runner, measured: false);
    }

    /** The minutes a CI bills for each of these jobs' times, each rounded up to a whole minute (ADR-0240). */
    public static function billedFor(Seconds ...$jobs): int
    {
        $minutes = 0;

        foreach ($jobs as $job) {
            $minutes += (int) ceil($job->seconds() / Seconds::PER_MINUTE);
        }

        return $minutes;
    }

    /** This run time, whose jobs a CI bills for this many minutes. */
    public function billing(int $minutes): self
    {
        return new self($this->wall, $this->runner, $this->measured, $minutes);
    }

    /** The minutes the run's CI jobs bill, each job rounded up to a whole minute; none for a run on no CI. */
    public function billed(): int|NotGiven
    {
        return $this->billed;
    }

    public function wall(): Seconds
    {
        return $this->wall;
    }

    public function runner(): Seconds
    {
        return $this->runner;
    }

    /** Whether it was measured, rather than the gate estimating each job's setup. */
    public function isMeasured(): bool
    {
        return $this->measured;
    }
}
