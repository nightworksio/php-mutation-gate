<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_map;
use function count;
use function max;

use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

/**
 * The next runner invocation of a run that goes in batches: a budgeted run
 * (ADR-0008), and a pull request's shard that stops once its
 * verdict cannot pass. Units are taken in the order given: a
 * held unit alone, against the tests that hold it, and otherwise as many
 * units in a row as fit the time left and the chunk, since every invocation
 * pays the runner's opening run. A unit fits the time left only with the
 * unmutated control its kills ask for: the batch's controls run side by
 * side in the pool, round after round, each round as long as the longest,
 * after its mutants, so a batch never takes a unit whose kills it has no
 * time to control. Where the first unit does not fit the time left,
 * nothing is started; a chunk always starts with its first unit.
 *
 * @internal
 */
final readonly class Batching
{
    /** How much expected work a chunk of a shard that can stop once it cannot pass holds (ADR-0032). */
    public const int CHUNK_MINUTES = 15;

    private function __construct(
        private Seconds $opening,
        private Seconds|Unlimited $chunk,
        private ProcessCount $abreast,
    ) {
    }

    /** Batches whose every invocation opens with a run that takes this long, its controls one at a time. */
    public static function opening(Seconds $opening): self
    {
        return new self($opening, Unlimited::time(), ProcessCount::single());
    }

    /** These batches, each ending once the next unit takes it, opening run and all, past this. */
    public function inChunksOf(Seconds $chunk): self
    {
        return new self($this->opening, $chunk, $this->abreast);
    }

    /** These batches, their controls run this many side by side. */
    public function controlledAbreast(ProcessCount $abreast): self
    {
        return new self($this->opening, $this->chunk, $abreast);
    }

    /**
     * The units of the next invocation, none where the first does not fit.
     *
     * @param list<Weighed> $ordered the units left, in the order they are taken, each with what it costs
     */
    public function next(array $ordered, Seconds|Unlimited $left): Units
    {
        $batch = Units::none();
        $spent = $this->opening->seconds();
        $controls = [];

        foreach ($ordered as $weighed) {
            $unit = $weighed->unit();
            $spent += $weighed->cost()->seconds();
            $controls = $weighed->control()->seconds() > 0.0 ? [...$controls, $weighed->control()] : $controls;

            if ($this->ends($batch, $unit, $spent, $controls, $left)) {
                break;
            }

            $batch = $batch->with($unit);

            if ($unit->isHeld()) {
                break;
            }
        }

        return $batch;
    }

    /**
     * Whether the batch ends before this unit: a held unit, or one past the
     * chunk, after others, or one past the time left, its control and all.
     *
     * @param list<Seconds> $controls
     */
    private function ends(Units $batch, Unit $unit, float $spent, array $controls, Seconds|Unlimited $left): bool
    {
        $full = $batch->count() > 0 && ($unit->isHeld() || $this->past($spent, $this->chunk));

        return $full || $this->past($spent, $left) || ! $this->controlled($controls, $spent, $left);
    }

    /**
     * Whether these controls, run side by side after the batch's mutants,
     * fit in the time left once those are spent: always without a limit.
     *
     * @param list<Seconds> $controls
     */
    private function controlled(array $controls, float $spent, Seconds|Unlimited $left): bool
    {
        if (! $left instanceof Seconds || $controls === []) {
            return true;
        }

        $longest = max(array_map(static fn(Seconds $control): float => $control->seconds(), $controls));
        $fitting = Deadline::fittingIn(
            Seconds::of($left->seconds() - $spent),
            count($controls),
            Seconds::of($longest),
            $this->abreast->count(),
        );

        return $fitting === count($controls);
    }

    private function past(float $spent, Seconds|Unlimited $limit): bool
    {
        return $limit instanceof Seconds && $spent > $limit->seconds();
    }
}
