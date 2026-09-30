<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Units;

/**
 * The next runner invocation of a budgeted run (ADR-0008, decision 1). Units
 * are taken in the order given: a held unit alone, against the tests that
 * hold it, and otherwise as many units in a row as fit the time left, since
 * every invocation pays the runner's opening run. Where the first unit does
 * not fit, nothing is started.
 */
final readonly class Batching
{
    private function __construct(private Seconds $opening)
    {
    }

    /** Batches whose every invocation opens with a run that takes this long. */
    public static function opening(Seconds $opening): self
    {
        return new self($opening);
    }

    /**
     * The units of the next invocation, none where the first does not fit.
     *
     * @param list<Weighed> $ordered the units left, in the order they are taken, each with what it costs
     */
    public function next(array $ordered, Seconds $left): Units
    {
        $batch = Units::none();
        $spent = $this->opening->seconds();

        foreach ($ordered as $weighed) {
            $unit = $weighed->unit();
            $spent += $weighed->cost()->seconds();

            if (($batch->count() > 0 && $unit->isHeld()) || $spent > $left->seconds()) {
                break;
            }

            $batch = $batch->with($unit);

            if ($unit->isHeld()) {
                break;
            }
        }

        return $batch;
    }
}
