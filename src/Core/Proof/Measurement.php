<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Cost\JobTimes;
use NightWorksIO\MutationGate\Core\Cost\SharesTimed;
use NightWorksIO\MutationGate\Core\Cost\StepTimes;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * What a shard measured: the time it spent mutating, from the end of its
 * opening run to its last mutant, which runner spent it, when, the steps
 * its time went to (ADR-0016), and what its CI job measured of its own time
 * around the gate (ADR-0196), and the cores of its machine (ADR-0230).
 */
final readonly class Measurement
{
    private function __construct(
        private Seconds $spent,
        private string $runner,
        private Instant $at,
        private StepTimes $steps,
        private GateRelease $gate,
        private JobTimes $job,
        private SharesTimed $shares,
        private ProcessCount|NotGiven $cores = new NotGiven(),
    ) {
    }

    public static function of(Seconds $spent, string $runner, Instant $at): self
    {
        return new self(
            $spent,
            $runner,
            $at,
            StepTimes::none(),
            GateRelease::unrecorded(),
            JobTimes::none(),
            SharesTimed::none(),
        );
    }

    /** The same measurement, its time having gone to these steps. */
    public function withSteps(StepTimes $steps): self
    {
        return new self(
            $this->spent,
            $this->runner,
            $this->at,
            $steps,
            $this->gate,
            $this->job,
            $this->shares,
            $this->cores,
        );
    }

    /** The same measurement, made by this release of the gate. */
    public function measuredBy(GateRelease $gate): self
    {
        return new self(
            $this->spent,
            $this->runner,
            $this->at,
            $this->steps,
            $gate,
            $this->job,
            $this->shares,
            $this->cores,
        );
    }

    /** The same measurement, with what its CI job measured of its own time. */
    public function timingTheJob(JobTimes $job): self
    {
        return new self(
            $this->spent,
            $this->runner,
            $this->at,
            $this->steps,
            $this->gate,
            $job,
            $this->shares,
            $this->cores,
        );
    }

    /** The same measurement, with what the jobs that judged shares of what the shard left measured of their time. */
    public function sharedBy(JobTimes ...$shares): self
    {
        return new self(
            $this->spent,
            $this->runner,
            $this->at,
            $this->steps,
            $this->gate,
            $this->job,
            $this->shares->and(...$shares),
            $this->cores,
        );
    }

    /** The same measurement, made on a machine with these cores, where the gate counted them (ADR-0230). */
    public function onCores(ProcessCount|NotGiven $cores): self
    {
        return new self(
            $this->spent,
            $this->runner,
            $this->at,
            $this->steps,
            $this->gate,
            $this->job,
            $this->shares,
            $cores,
        );
    }

    /** The cores of the machine the shard ran on; not given where the gate did not count them. */
    public function cores(): ProcessCount|NotGiven
    {
        return $this->cores;
    }

    /** What the jobs that judged shares of what the shard left measured of their time. */
    public function shares(): SharesTimed
    {
        return $this->shares;
    }

    /** What its CI job measured of its own time around the gate; nothing where it measured none. */
    public function job(): JobTimes
    {
        return $this->job;
    }

    /** The release of the gate that made it; unrecorded where none was. */
    public function gate(): GateRelease
    {
        return $this->gate;
    }

    /** The steps the shard's time went to, each from when the shard began; none where it timed none. */
    public function steps(): StepTimes
    {
        return $this->steps;
    }

    public function spent(): Seconds
    {
        return $this->spent;
    }

    public function runner(): string
    {
        return $this->runner;
    }

    public function at(): Instant
    {
        return $this->at;
    }
}
