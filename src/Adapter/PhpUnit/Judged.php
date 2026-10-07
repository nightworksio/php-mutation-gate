<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

/** The mutants a mutation run judged, in the order they were made, what it warns of, and the evidence of its kills. */
final readonly class Judged
{
    /** @param list<Mutant> $mutants */
    private function __construct(private array $mutants, private Warnings $warnings, private Evidences $evidence)
    {
    }

    /** @param list<Mutant> $mutants */
    public static function of(array $mutants, Warnings $warnings): self
    {
        return new self($mutants, $warnings, Evidences::none());
    }

    /** These, with the evidence of these kills as well. */
    public function withEvidence(Evidences $evidence): self
    {
        return new self($this->mutants, $this->warnings, $this->evidence->and($evidence));
    }

    /** The evidence of its kills, by each mutant's id. */
    public function evidence(): Evidences
    {
        return $this->evidence;
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
