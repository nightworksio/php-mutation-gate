<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

/**
 * Where a CI definition `init` does not write belongs: printed for the person
 * to add to a file the CI reads, which `init` never edits (ADR-0015 decision
 * 13).
 */
final readonly class Printed
{
    private function __construct(private string $into)
    {
    }

    /** Printed, to be added to this file. */
    public static function into(string $file): self
    {
        return new self($file);
    }

    /** The file it belongs in, as a person names it. */
    public function file(): string
    {
        return $this->into;
    }
}
