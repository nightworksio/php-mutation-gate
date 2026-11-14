<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/**
 * The parts of one layer of config that decide what is judged and how a
 * result counts: the floors, what a change reaches, triage, what is
 * ignored, and whether mutants that are one program are judged once.
 */
final readonly class JudgingParts
{
    public function __construct(
        private Floors $floors,
        private Reach $reach,
        private Triage $triage,
        private Ignores $ignores,
        private Mutants $mutants,
    ) {
    }

    public function floors(): Floors
    {
        return $this->floors;
    }

    public function reach(): Reach
    {
        return $this->reach;
    }

    public function triage(): Triage
    {
        return $this->triage;
    }

    public function ignores(): Ignores
    {
        return $this->ignores;
    }

    /** Whether mutants that are one program are judged once (ADR-0137). */
    public function mutants(): Mutants
    {
        return $this->mutants;
    }
}
