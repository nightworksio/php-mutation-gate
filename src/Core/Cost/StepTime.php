<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * One stretch of a shard's time: the step it went to, how long after the
 * shard began it started, how long it took, and how many it handled.
 */
final readonly class StepTime
{
    private function __construct(private Step $step, private Seconds $since, private Seconds $took, private int $count)
    {
    }

    /** A step that started this long after the shard began and took this long, handling this many. */
    public static function of(Step $step, Seconds $since, Seconds $took, int $count = 1): self
    {
        return new self($step, $since, $took, $count);
    }

    /** A step as it was timed, having handled this many: a count known only once the step is over. */
    public static function counted(self $timed, int $count): self
    {
        return new self($timed->step, $timed->since, $timed->took, $count);
    }

    public function step(): Step
    {
        return $this->step;
    }

    /** How long after the shard began the step started. */
    public function since(): Seconds
    {
        return $this->since;
    }

    public function took(): Seconds
    {
        return $this->took;
    }

    /**
     * How many the step handled: the mutants a mutation step judged or a step
     * ran again, the sets of files of the baselines; one otherwise.
     */
    public function count(): int
    {
        return $this->count;
    }


    /** The same step, started this much later: timed from an earlier start than its own. */
    public function later(Seconds $by): self
    {
        return new self($this->step, Seconds::of($this->since->seconds() + $by->seconds()), $this->took, $this->count);
    }
}
