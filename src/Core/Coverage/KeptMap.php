<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

/**
 * A coverage map as a store keeps it beside a scope's ledger: the map, where
 * it was measured, and each test file's entry key (ADR-0023, decision 2).
 */
final readonly class KeptMap
{
    private function __construct(private CoverageMap $map, private MeasuredAt|Unplaced $at, private EntryKeys $keys)
    {
    }

    public static function of(CoverageMap $map, MeasuredAt|Unplaced $at, EntryKeys $keys): self
    {
        return new self($map, $at, $keys);
    }

    public function map(): CoverageMap
    {
        return $this->map;
    }

    public function measuredAt(): MeasuredAt|Unplaced
    {
        return $this->at;
    }

    public function keys(): EntryKeys
    {
        return $this->keys;
    }
}
