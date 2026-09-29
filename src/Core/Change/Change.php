<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Change;

use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;

/**
 * One path a change touched, and the lines on its new side that were added or
 * modified. A deleted file and a pure rename change no line.
 */
final readonly class Change
{
    private function __construct(
        private ChangeKind $kind,
        private Path $path,
        private Path $previousPath,
        private Lines $lines,
    ) {}

    public static function added(Path $path, Lines $lines): self
    {
        return new self(ChangeKind::Added, $path, $path, $lines);
    }

    public static function modified(Path $path, Lines $lines): self
    {
        return new self(ChangeKind::Modified, $path, $path, $lines);
    }

    public static function deleted(Path $path): self
    {
        return new self(ChangeKind::Deleted, $path, $path, Lines::none());
    }

    public static function renamed(Path $from, Path $to, Lines $lines): self
    {
        return new self(ChangeKind::Renamed, $to, $from, $lines);
    }

    public function kind(): ChangeKind
    {
        return $this->kind;
    }

    /** The path on the new side; for a deletion, the path that was deleted. */
    public function path(): Path
    {
        return $this->path;
    }

    /** The path on the old side, which differs from the new one only for a rename. */
    public function previousPath(): Path
    {
        return $this->previousPath;
    }

    public function lines(): Lines
    {
        return $this->lines;
    }
}
