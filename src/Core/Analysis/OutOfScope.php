<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use NightWorksIO\MutationGate\Core\File\Path;

/**
 * A file the analyser does not analyse, as it is outside the paths its
 * config names (ADR-0020, decision 7). A mutant of it is left to its tests,
 * unchecked, and the run says so.
 */
final readonly class OutOfScope
{
    private function __construct(private Path $file)
    {
    }

    public static function of(Path $file): self
    {
        return new self($file);
    }

    public function file(): Path
    {
        return $this->file;
    }
}
