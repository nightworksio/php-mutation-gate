<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * A survivor static analysis left unchecked: why, the file it is a mutant
 * of, and, for a check that could not run, the reason given where one was.
 */
final readonly class UncheckedSurvivor
{
    private function __construct(private Unchecked $why, private Path $file, private string|NotGiven $reason)
    {
    }

    public static function of(Unchecked $why, Path $file): self
    {
        return new self($why, $file, NotGiven::value());
    }

    /** A survivor whose check could not run, for the reason the analyser, or the gate, gave. */
    public static function failed(string $reason, Path $file): self
    {
        return new self(Unchecked::Failed, $file, $reason);
    }

    public function why(): Unchecked
    {
        return $this->why;
    }

    public function file(): Path
    {
        return $this->file;
    }

    /** Why its check could not run, where that was said. */
    public function reason(): string|NotGiven
    {
        return $this->reason;
    }
}
