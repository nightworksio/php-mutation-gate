<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_map;
use function array_slice;

use Closure;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Plan\Batching;
use NightWorksIO\MutationGate\Core\Plan\Weighed;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Doom;
use NightWorksIO\MutationGate\Core\Verdict\Undoomed;
use Psr\Clock\ClockInterface;

/**
 * A shard's units run in batches (ADR-0008), in the
 * order the plan lists them, riskiest first: each batch fits the time left,
 * where the run has a deadline, and its chunk, where the run stops once it
 * cannot pass. The batches run until the next does not fit, the time is up,
 * the run is interrupted, or, where `run.failFast` stops it, a batch's
 * survivor makes the run certain to fail: one static analysis does not clear,
 * neither killing it nor proving it equivalent (see SurvivorChecking and
 * Invoking); under `mark` the first such survivor is noted and the batches run
 * on (ADR-0103). A batch the runner
 * cannot judge once the time is up is unjudged, as is every unit no batch
 * took. What each batch judged is told as it ends (ADR-0120). Where a
 * change cancels by unit, the units it moved leave the queue unjudged, and a
 * batch it halts leaves its units unjudged as a deadline would (ADR-0146).
 *
 * @internal
 */
final readonly class Batched
{
    public function __construct(
        private Invoking $invoking,
        private ClockInterface $clock,
        private Deadline|Unlimited $deadline,
        private Deadline|Unlimited $fittedTo,
        private Interruption $interruption,
        private Doom|Undoomed $doom,
        private bool $stops,
        private BatchEnds $ends = new BatchEnds(),
    ) {
    }

    /**
     * What the batches came to, or the cannot judge of one the runner could not judge before the time was up.
     *
     * @param list<Weighed>                  $queue      the units in the order they run, each with what it costs
     * @param Closure(Units): MutationRequest $requestFor the invocation that runs a batch
     */
    public function spent(array $queue, Batching $batching, Closure $requestFor): Spent|CannotJudge
    {
        $spent = Spent::none();
        $left = $this->left();
        $watch = DoomWatch::from($this->doom, $this->clock, $this->stops);

        while (true) {
            $kept = $this->interruption->kept($queue);
            $spent = $spent->leaving(self::unitsOf($queue)->except(self::unitsOf($kept)));
            $queue = $kept;
            $batch = $watch->stopsAfter($spent) ? Units::none() : $this->batchOf($batching, $queue, $left);

            if ($batch->count() === 0) {
                break;
            }

            $request = $requestFor($batch);
            $halt = $this->interruption->halting($batch);
            $invoked = $this->invoking->invoked($this->within($request, $left));
            $halted = $halt instanceof BatchHalt && $halt->disarmed();
            $this->ends->ended($invoked);
            $queue = array_slice($queue, $batch->count());
            $spent = match (true) {
                ! $invoked instanceof CannotJudge => $watch->after($spent, $batch, $invoked),
                $halted || $this->isOver() => $spent->leaving($batch),
                default => $invoked,
            };

            if ($spent instanceof CannotJudge) {
                return $spent;
            }

            $left = $this->left();
        }

        return $spent->leaving(self::unitsOf($queue));
    }

    /**
     * The units of these.
     *
     * @param list<Weighed> $weighed
     */
    private static function unitsOf(array $weighed): Units
    {
        return Units::of(...array_map(static fn(Weighed $one): Unit => $one->unit(), $weighed));
    }

    /** The time left before the deadline, or no limit where the run has none. */
    private function left(): Seconds|Unlimited
    {
        return $this->deadline instanceof Deadline ? $this->deadline->left($this->clock->now()) : $this->deadline;
    }

    /** A batch's request, within the time left where the run has a deadline. */
    private function within(MutationRequest $request, Seconds|Unlimited $left): MutationRequest
    {
        return $left instanceof Seconds ? $request->within($left) : $request;
    }

    /** Whether the deadline has passed; never where the run has none. */
    private function isOver(): bool
    {
        return $this->deadline instanceof Deadline && $this->deadline->hasPassed($this->clock->now());
    }

    /**
     * The next batch whose estimate fits a budget's time left, or none once
     * the run is interrupted. `shards.jobLimit` turns no unit away on its
     * estimate: a unit starts, and the runner stops at the limit.
     *
     * @param list<Weighed> $queue
     */
    private function batchOf(Batching $batching, array $queue, Seconds|Unlimited $left): Units
    {
        $fitted = $this->fittedTo instanceof Deadline ? $left : $this->fittedTo;

        return $this->interruption->arrived() ? Units::none() : $batching->next($queue, $fitted);
    }
}
