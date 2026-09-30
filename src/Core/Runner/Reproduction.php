<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\Reason;

/**
 * One mutant run again on its own, as the runner judged it this time, or why
 * the run made no such mutant, with what the runner printed doing so
 * (ADR-0004 decision 6).
 */
final readonly class Reproduction
{
    private function __construct(private Mutant|Unmade $mutant, private string $printed)
    {
    }

    /**
     * The mutant of a run with this id, or the run made none, for the reason
     * the runner gives; and what the runner printed.
     */
    public static function among(MutantId $id, Mutants $ran, Reason $unmade, string $printed): self
    {
        $found = Unmade::because($unmade);

        foreach ($ran as $mutant) {
            $found = $mutant->id()->value() === $id->value() ? $mutant : $found;
        }

        return new self($found, $printed);
    }

    public function mutant(): Mutant|Unmade
    {
        return $this->mutant;
    }

    /** What the runner printed, as it printed it. */
    public function printed(): string
    {
        return $this->printed;
    }
}
