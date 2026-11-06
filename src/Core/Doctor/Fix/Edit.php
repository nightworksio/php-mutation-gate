<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Fix;

use NightWorksIO\MutationGate\Core\Migration\UnifiedDiff;

/**
 * One file `doctor --fix` changes (ADR-0083): where it is, as a path the
 * project or the file system's root spells, what it held when doctor read
 * it, and what it is to hold.
 *
 * @internal
 */
final readonly class Edit
{
    private function __construct(private string $file, private string $before, private string $after)
    {
    }

    public static function of(string $file, string $before, string $after): self
    {
        return new self($file, $before, $after);
    }

    /** The file, as the project or the file system's root spells it. */
    public function file(): string
    {
        return $this->file;
    }

    public function before(): string
    {
        return $this->before;
    }

    public function after(): string
    {
        return $this->after;
    }

    /** Whether the edit changes nothing. */
    public function isNone(): bool
    {
        return $this->before === $this->after;
    }

    /** The change, as `diff -u` shows it. */
    public function diff(): string
    {
        return UnifiedDiff::between($this->file, $this->before, $this->after);
    }
}
