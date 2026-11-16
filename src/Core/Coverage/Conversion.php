<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * What the gate decides of the map of a run's report before an engine writes
 * it (ADR-0119): the report's outline, which names each file's path; what of
 * it the suites admit, where they leave any test out; where it was measured;
 * and its entries' keys, where a store keeps it.
 *
 * @internal
 */
final readonly class Conversion
{
    private function __construct(
        private MapOutline $outline,
        private Admission|NotGiven $admission,
        private MeasuredAt|Unplaced $at,
        private EntryKeys|NotGiven $keys,
    ) {
    }

    public static function of(
        MapOutline $outline,
        Admission|NotGiven $admission,
        MeasuredAt|Unplaced $at,
        EntryKeys|NotGiven $keys,
    ): self {
        return new self($outline, $admission, $at, $keys);
    }

    public function outline(): MapOutline
    {
        return $this->outline;
    }

    /** What of the map the suites admit; none where they admit it whole. */
    public function admission(): Admission|NotGiven
    {
        return $this->admission;
    }

    public function at(): MeasuredAt|Unplaced
    {
        return $this->at;
    }

    public function keys(): EntryKeys|NotGiven
    {
        return $this->keys;
    }
}
