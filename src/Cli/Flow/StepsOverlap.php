<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\PendingControls;

/**
 * An invocation's controls started beside its survivors' runs again
 * (`pest.overlapSteps`): the request the survivors run again in, a share of
 * the pool, and the controls running in the rest of it, timed as the
 * controls step from their start to their ends; or none, the survivors
 * running in the whole pool.
 *
 * @internal
 */
final readonly class StepsOverlap
{
    private function __construct(
        private MutationRequest $confirming,
        private PendingControls|NotGiven $pending,
        private DateTimeImmutable|NotGiven $from,
        private int $count,
    ) {
    }

    /** Nothing started: the survivors run again in the request's whole pool. */
    public static function none(MutationRequest $request): self
    {
        return new self($request, NotGiven::value(), NotGiven::value(), 0);
    }

    /** These controls, this many, started at this time, the survivors running again in this request's pool beside them. */
    public static function started(
        PendingControls $pending,
        DateTimeImmutable $from,
        int $count,
        MutationRequest $confirming,
    ): self {
        return new self($confirming, $pending, $from, $count);
    }

    /** The request the survivors run again in. */
    public function confirming(): MutationRequest
    {
        return $this->confirming;
    }

    /** What the controls started found, once all have ended, timed on the stopwatch; nothing where none started. */
    public function taken(Stopwatch $stopwatch): ControlRuns|CannotJudge|NotGiven
    {
        $pending = $this->pending;
        $from = $this->from;

        if (! $pending instanceof PendingControls || ! $from instanceof DateTimeImmutable) {
            return NotGiven::value();
        }

        $runs = $pending->taken();
        $stopwatch->handled(Step::Controls, $from, $this->count);

        return $runs;
    }

    /** Every control started and still running stopped. */
    public function stop(): void
    {
        if ($this->pending instanceof PendingControls) {
            $this->pending->stop();
        }
    }
}
