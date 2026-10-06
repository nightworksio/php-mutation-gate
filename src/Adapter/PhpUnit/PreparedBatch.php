<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function array_keys;
use function array_values;
use function count;
use function hrtime;
use function ksort;
use function max;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

use const PHP_INT_MAX;

/**
 * Mutants judged in the order they were made: each without a run as it comes,
 * and each prepared run in a batch, the batch run side by side once it is
 * full or the last mutant has come. Once a batch's runs could not all start
 * by the deadline, on `hrtime`'s clock, the batch has run out of time, and so
 * has every mutant after.
 */
final class PreparedBatch
{
    /** @var array<int, Mutant> each mutant judged, by the order it came in */
    private array $judged = [];

    /** @var array<int, PreparedRun> each run waiting for its batch, by the order it came in */
    private array $waiting = [];

    private bool $stopped = false;

    /** @param positive-int $size */
    private function __construct(
        private readonly MutantRun $run,
        private readonly WorkerSlots $slots,
        private readonly int $size,
        private readonly int|float $end,
    ) {
    }

    /**
     * Batches of this many runs, judged in these places, none started once
     * `hrtime` reaches the end: never, at `PHP_INT_MAX`.
     *
     * @param positive-int $size
     */
    public static function of(MutantRun $run, WorkerSlots $slots, int $size, int|float $end): self
    {
        return new self($run, $slots, $size, $end);
    }

    /** Whether no more mutants are judged: the deadline has come, or a batch's runs could not all start by it. */
    public function hasRunOut(): bool
    {
        return $this->stopped || hrtime(as_number: true) >= $this->end;
    }

    /**
     * The next mutant: judged without a run, or a run for its batch, which runs once full. Its place is how many
     * came before it, every one judged or waiting until a batch has run out, after which none is added.
     */
    public function add(PreparedRun|Mutant $prepared): void
    {
        $at = count($this->judged) + count($this->waiting);

        if ($prepared instanceof Mutant) {
            $this->judged[$at] = $prepared;

            return;
        }

        $this->waiting[$at] = $prepared;

        if (count($this->waiting) >= $this->size) {
            $this->ranWaiting();
        }
    }

    /**
     * Every mutant judged, the last batch run first, in the order they came.
     *
     * @return list<Mutant>
     */
    public function finished(): array
    {
        $this->ranWaiting();
        ksort($this->judged);

        return array_values($this->judged);
    }

    private function ranWaiting(): void
    {
        if ($this->waiting === []) {
            return;
        }

        $positions = array_keys($this->waiting);
        $ended = $this->run->judgedSideBySide($this->slots, $this->remaining(), ...array_values($this->waiting));

        foreach ($ended as $at => $mutant) {
            $this->judged[$positions[$at]] = $mutant;
        }

        $this->stopped = count($ended) < count($positions);
        $this->waiting = [];
    }

    /** The time left to start runs in, by the deadline; all the time there is, where there is none. */
    private function remaining(): Seconds|Unlimited
    {
        return $this->end === PHP_INT_MAX
            ? Unlimited::time()
            : Seconds::of(max(0.0, ($this->end - hrtime(as_number: true)) / Seconds::NANOSECONDS));
    }
}
