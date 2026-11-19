<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use Closure;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Doom;
use NightWorksIO\MutationGate\Core\Verdict\Undoomed;
use Psr\Clock\ClockInterface;

/**
 * A shard that runs every invocation it plans, one after another, with no budget to batch it for and no doom to
 * stop at, leaving unjudged each invocation its deadline passed before: where it watches for the survivor that
 * leaves its run no way to pass, it notes the first and judges on, as `run.failFast: mark` has it (ADR-0103).
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
            $spent = $this->ran($spent, $units, $requestFor($units), $watch);

            if ($spent instanceof CannotJudge) {
                return $spent;
            }
        }

        return $spent;
    }

    /** What was spent once these units' invocation ran; these units left, where the deadline passed before it. */
    private function ran(Spent $spent, Units $units, MutationRequest $request, DoomWatch $watch): Spent|CannotJudge
    {
        $left = $this->left();

        if ($left instanceof Seconds && $left->seconds() <= 0.0) {
            return $spent->leaving($units);
        }

        $invoked = $this->invoking->invoked($left instanceof Seconds ? $request->within($left) : $request);

        return $invoked instanceof CannotJudge
            ? $this->leavingPastTheDeadline($spent, $units, $invoked)
            : $watch->after($spent, $units, $invoked);
    }

    /** What was spent, these units left, where an invocation that could not judge ran into the deadline; else why. */
    private function leavingPastTheDeadline(Spent $spent, Units $units, CannotJudge $invoked): Spent|CannotJudge
    {
        return $this->isOver() ? $spent->leaving($units) : $invoked;
    }

    /** Whether the shard's deadline has passed; never where it has none. */
    private function isOver(): bool
    {
        return $this->deadline instanceof Deadline && $this->deadline->hasPassed($this->clock->now());
    }

    /** The time left before the shard's deadline, which an invocation's request runs within; none where it has none. */
    private function left(): Seconds|Unlimited
    {
        return $this->deadline instanceof Deadline ? $this->deadline->left($this->clock->now()) : $this->deadline;
    }
}
