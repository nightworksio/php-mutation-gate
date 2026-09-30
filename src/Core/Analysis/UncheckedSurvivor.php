<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use NightWorksIO\MutationGate\Core\File\Path;

/** A survivor static analysis left unchecked: why, and the file it is a mutant of. */
final readonly class UncheckedSurvivor
{
    private function __construct(private Unchecked $why, private Path $file)
    {
    }

    public static function of(Unchecked $why, Path $file): self
    {
        return new self($why, $file);
    }

    public function why(): Unchecked
    {
        return $this->why;
    }

    public function file(): Path
    {
        return $this->file;
    }
}
