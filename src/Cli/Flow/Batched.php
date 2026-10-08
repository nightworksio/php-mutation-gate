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
use NightWorksIO\MutationGate\Core\Verdict\Doomed;
use NightWorksIO\MutationGate\Core\Verdict\Undoomed;
use Psr\Clock\ClockInterface;

/**
 * A shard's units run in batches (ADR-0008, decisions 1 and 6), in the
 * order the plan lists them, riskiest first: each batch fits the time left,
 * where the run has a deadline, and its chunk, where the run stops once it
 * cannot pass. The batches run until the next does not fit, the time is up,
 * the run is interrupted, or a batch's survivor makes the run certain to
 * fail: one static analysis does not clear, neither killing it nor proving
 * it equivalent (see SurvivorChecking and Invoking). A batch the runner
 * cannot judge once the time is up is unjudged, as is every unit no batch
 * took.
 */
final readonly class Batched
{
    public function __construct(
        private Invoking $invoking,
        private ClockInterface $clock,
        private Deadline|Unlimited $deadline,
        private Interruption $interruption,
        private Doom|Undoomed $doom,
        private SurvivorChecking $checking,
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
        $batch = $this->batchOf($batching, $queue, $left);

        while ($batch->count() > 0) {
            $request = $requestFor($batch);
            $invoked = $this->invoking->invoked($left instanceof Seconds ? $request->within($left) : $request);
            $queue = array_slice($queue, $batch->count());
            $spent = match (true) {
                ! $invoked instanceof CannotJudge => $this->doomed($spent, $batch, $invoked),
                $this->isOver() => $spent->leaving($batch),
                default => $invoked,
            };

            if ($spent instanceof CannotJudge) {
                return $spent;
            }

            $left = $this->left();
            $batch = $spent->doomed instanceof Doomed ? Units::none() : $this->batchOf($batching, $queue, $left);
        }

        return $spent->leaving(Units::of(...array_map(static fn(Weighed $weighed): Unit => $weighed->unit(), $queue)));
    }

    /**
     * What was spent, and this batch: where the run stops once it cannot
     * pass, its survivors checked by static analysis first, and stopped on
     * the first one left that makes the run certain to fail, if one does.
     */
    private function doomed(Spent $spent, Units $batch, Invoked $invoked): Spent
    {
        if (! $this->doom instanceof Doom) {
            return $spent->after($invoked);
        }

        $checked = $this->checking->checked($invoked->result->mutants(), $invoked->flaky);
        $after = $spent
            ->after(new Invoked(
                $invoked->result->withMutants($checked->mutants),
                $invoked->flaky,
                $invoked->equivalent,
            ))
            ->checkedBy($checked);
        $doomed = $this->doom->first($batch, $checked->mutants, $invoked->flaky, $invoked->equivalent);

        return $doomed instanceof Doomed ? $after->doomedBy($doomed) : $after;
    }

    /** The time left before the deadline, or no limit where the run has none. */
    private function left(): Seconds|Unlimited
    {
        return $this->deadline instanceof Deadline ? $this->deadline->left($this->clock->now()) : $this->deadline;
    }

    /** Whether the deadline has passed; never where the run has none. */
    private function isOver(): bool
    {
        return $this->deadline instanceof Deadline && $this->deadline->hasPassed($this->clock->now());
    }

    /**
     * The next batch that fits, or none once the run is interrupted.
     *
     * @param list<Weighed> $queue
     */
    private function batchOf(Batching $batching, array $queue, Seconds|Unlimited $left): Units
    {
        return $this->interruption->arrived() ? Units::none() : $batching->next($queue, $left);
    }
}
