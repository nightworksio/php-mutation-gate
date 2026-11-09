<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use Closure;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Doom;
use NightWorksIO\MutationGate\Core\Verdict\Undoomed;
use Psr\Clock\ClockInterface;

/**
 * A shard that runs every invocation it plans, one after another, with no deadline to batch it for and no doom to
 * stop at: where it watches for the survivor that leaves its run no way to pass, it notes the first and judges on,
 * as `run.failFast: mark` has it (ADR-0103).
 *
 * @internal
 */
final readonly class Unbatched
{
    public function __construct(
        private Invoking $invoking,
        private ClockInterface $clock,
        private Doom|Undoomed $doom,
        private Deadline|Unlimited $deadline,
    ) {
    }

    /** @param Closure(Units): MutationRequest $requestFor the invocation that runs a set of units */
    public function spent(Shard $shard, Closure $requestFor): Spent|CannotJudge
    {
        $spent = Spent::none();
        $watch = DoomWatch::from($this->doom, $this->clock, stops: false);

        foreach ($shard->invocations() as $units) {
            $invoked = $this->invoking->invoked($this->within($requestFor($units)));
            $spent = $invoked instanceof CannotJudge
                ? $this->leavingPastTheDeadline($spent, $units, $invoked)
                : $watch->after($spent, $units, $invoked);

            if ($spent instanceof CannotJudge) {
                return $spent;
            }
        }

        return $spent;
    }

    /** What was spent, these units left, where an invocation that could not judge ran into the deadline; else why. */
    private function leavingPastTheDeadline(Spent $spent, Units $units, CannotJudge $invoked): Spent|CannotJudge
    {
        return $this->deadline instanceof Deadline && $this->deadline->hasPassed($this->clock->now())
            ? $spent->leaving($units)
            : $invoked;
    }

    /** A request, within the time left where the shard has a deadline; what it judged by then stands. */
    private function within(MutationRequest $request): MutationRequest
    {
        return $this->deadline instanceof Deadline
            ? $request->within($this->deadline->left($this->clock->now()))
            : $request;
    }
}
