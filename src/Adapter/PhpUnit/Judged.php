<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

/** The mutants a mutation run judged, in the order they were made, and what it warns of. */
final readonly class Judged
{
    /** @param list<Mutant> $mutants */
    private function __construct(private array $mutants, private Warnings $warnings)
    {
    }

    /** @param list<Mutant> $mutants */
    public static function of(array $mutants, Warnings $warnings): self
    {
        return new self($mutants, $warnings);
    }

    /** @return list<Mutant> */
    public function mutants(): array
    {
        return $this->mutants;
    }

    public function warnings(): Warnings
    {
        return $this->warnings;
    }
}
