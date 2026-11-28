<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use function max;

use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * What one shard spent, as the cost report reads it (ADR-0230): its
 * seconds, the steps they went to, the cores of its machine, and how many
 * jobs of a second wave judged shares of what it left (ADR-0190).
 *
 * @internal
 */
final readonly class ShardSpend
{
    private function __construct(
        private Seconds $seconds,
        private StepTimes $steps,
        private ProcessCount $cores,
        private int $shares,
    ) {
    }

    public static function of(Seconds $seconds, StepTimes $steps, ProcessCount $cores, int $shares): self
    {
        return new self($seconds, $steps, $cores, $shares);
    }

    public function seconds(): Seconds
    {
        return $this->seconds;
    }

    public function cores(): ProcessCount
    {
        return $this->cores;
    }

    /** How many jobs of a second wave judged shares of what the shard left. */
    public function shares(): int
    {
        return $this->shares;
    }

    /**
     * The wall seconds the shard spent in a route: the steps that are the
     * route's alone, and for its own runs, its mutation steps less the
     * passes of split streams within them (ADR-0196).
     */
    public function in(Route $route): Seconds
    {
        if ($route === Route::OwnRun) {
            return Seconds::of(max(
                0.0,
                $this->took(Step::Mutation) - $this->took(Step::FirstPass) - $this->took(Step::SecondPass),
            ));
        }

        $seconds = 0.0;

        foreach ($this->steps as $step) {
            $seconds += Route::timing($step->step()) === $route ? $step->took()->seconds() : 0.0;
        }

        return Seconds::of($seconds);
    }

    /** The seconds the shard's steps of this kind took, together. */
    private function took(Step $kind): float
    {
        $seconds = 0.0;

        foreach ($this->steps as $step) {
            $seconds += $step->step() === $kind ? $step->took()->seconds() : 0.0;
        }

        return $seconds;
    }
}
