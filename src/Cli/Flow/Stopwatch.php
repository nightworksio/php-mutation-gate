<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function count;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTime;
use NightWorksIO\MutationGate\Core\Cost\StepTimes;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use Psr\Clock\ClockInterface;

/**
 * The steps a shard's time went to, as the flows time them on the PSR-20
 * clock (ADR-0016, decision 19), each from when the shard began.
 */
final class Stopwatch
{
    private StepTimes $steps;

    public function __construct(private readonly ClockInterface $clock, private readonly DateTimeImmutable $began)
    {
        $this->steps = StepTimes::none();
    }

    /** When a step begins. */
    public function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }

    /** A step that began then and ends now, having handled this many. */
    public function stop(Step $step, DateTimeImmutable $from, int $count = 1): void
    {
        $this->steps = $this->steps->and(StepTimes::of(StepTime::of(
            $step,
            Seconds::between($this->began, $from),
            Seconds::between($from, $this->clock->now()),
            $count,
        )));
    }

    /** A step that began then and ends now, having handled this many; no step where it handled none. */
    public function handled(Step $step, DateTimeImmutable $from, int $count): void
    {
        if ($count > 0) {
            $this->stop($step, $from, $count);
        }
    }

    /**
     * The steps a runner timed itself in a run that began then, each from
     * when that run began; or, where it timed none, the run whole as its
     * mutation step.
     */
    public function ran(StepTimes $own, DateTimeImmutable $from): void
    {
        if (count($own) === 0) {
            $this->stop(Step::Mutation, $from);

            return;
        }

        $this->steps = $this->steps->and($own->later(Seconds::between($this->began, $from)));
    }

    public function steps(): StepTimes
    {
        return $this->steps;
    }
}
