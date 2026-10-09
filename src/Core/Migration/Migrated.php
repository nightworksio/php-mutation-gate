<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Migration;

use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * A file as it is and as `migrate` would write it (ADR-0026, decision 5):
 * the changes it can make, those left for a hand edit, and what the reader
 * must know before it is written.
 */
final readonly class Migrated
{
    private function __construct(
        private string $file,
        private string $before,
        private string $after,
        private Invalid|NotGiven $left,
        private string $note,
    ) {
    }

    /** A file named as the reader names it, as it is, as it would be written, and the changes left for a hand edit. */
    public static function of(string $file, string $before, string $after, Invalid|NotGiven $left): self
    {
        return new self($file, $before, $after, $left, '');
    }

    /** This migration, telling the reader this before anything is written. */
    public function noting(string $note): self
    {
        return new self($this->file, $this->before, $this->after, $this->left, $note);
    }

    public function file(): string
    {
        return $this->file;
    }

    /** Whether `migrate` would write the file. */
    public function changes(): bool
    {
        return $this->before !== $this->after;
    }

    /** The file as `migrate` would write it. */
    public function after(): string
    {
        return $this->after;
    }

    /** The changes only a hand edit can make; nothing where none is left. */
    public function left(): Invalid|NotGiven
    {
        return $this->left;
    }

    /** What the reader must know before the file is written; nothing where there is no such thing. */
    public function note(): string
    {
        return $this->changes() ? $this->note : '';
    }

    /** The changes `migrate` would make, as `diff -u` shows them. */
    public function diff(): string
    {
        return UnifiedDiff::between($this->file, $this->before, $this->after);
    }
}
