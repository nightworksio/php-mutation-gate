<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * A survivor static analysis left unchecked: why, the file it is a mutant
 * of, and what was said of it where anything was: why a check could not run,
 * or which findings a print that analyses otherwise differs by.
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
        return self::told(Unchecked::Failed, $reason, $file);
    }

    /** A survivor of a file whose print analyses otherwise than the file as written, with the findings that differ. */
    public static function differing(DifferingPrint $print, Path $file): self
    {
        return self::told(Unchecked::PrintDiffers, $print->detail(), $file);
    }

    /** A survivor left unchecked for a reason, with what was said of it. */
    public static function told(Unchecked $why, string $reason, Path $file): self
    {
        return new self($why, $file, $reason);
    }

    public function why(): Unchecked
    {
        return $this->why;
    }

    public function file(): Path
    {
        return $this->file;
    }

    /** What was said of why it was left, where anything was. */
    public function reason(): string|NotGiven
    {
        return $this->reason;
    }
}
