<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;

/**
 * What a runner needs to make one mutant again: its file, its mutator by the
 * runner's full name for it, and the gate's id to match it back by.
 */
final readonly class Reproducible
{
    private function __construct(private MutantId $id, private Path $file, private string $mutator)
    {
    }

    /** A mutant as a run reported it, or a kill as a ledger proved it. */
    public static function of(Mutant|ProvedKill $recorded): self
    {
        return new self(
            $recorded->id(),
            $recorded->location()->file(),
            $recorded->mutator(),
        );
    }

    public function id(): MutantId
    {
        return $this->id;
    }

    public function file(): Path
    {
        return $this->file;
    }

    public function mutator(): string
    {
        return $this->mutator;
    }
}
