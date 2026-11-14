<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use Psr\Clock\ClockInterface;

/**
 * The time a run's budget leaves it, read now: how many runs fit in it, and
 * a request timed by it; every run, and the request as it was, without a
 * budget.
 *
 * @internal
 */
final readonly class TimeLeft
{
    public function __construct(private Deadline|Unlimited $deadline, private ClockInterface $clock)
    {
    }

    /**
     * The request, timed by what the budget has left now, so a run again
     * ends by the deadline the invocation had to; as it was without a budget.
     */
    public function retimed(MutationRequest $request): MutationRequest
    {
        return $this->deadline instanceof Deadline
            ? $request->within($this->deadline->left($this->clock->now()))
            : $request;
    }

    /** How many runs, each of which may take this long, fit in the time left: every one without a budget. */
    public function fitting(int $wanted, Seconds $each): int
    {
        return $this->deadline instanceof Deadline
            ? $this->deadline->fitting($wanted, $each, $this->clock->now())
            : $wanted;
    }
}
