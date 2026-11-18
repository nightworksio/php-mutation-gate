<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;

/**
 * A mutant a shard left at its deadline, as a share of the second wave lists
 * it (ADR-0190): the gate's id, the runner's own id, which a job of the
 * second wave makes it again by, and the file it mutates.
 *
 * @internal
 */
final readonly class LeftMutant
{
    private function __construct(private MutantId $id, private string $native, private Path $file)
    {
    }

    public static function of(MutantId $id, string $native, Path $file): self
    {
        return new self($id, $native, $file);
    }

    public function id(): MutantId
    {
        return $this->id;
    }

    public function native(): string
    {
        return $this->native;
    }

    public function file(): Path
    {
        return $this->file;
    }
}
