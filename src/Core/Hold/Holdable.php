<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hold;

use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Tree\Trees;

/** What a test may declare it holds: a tree, or a file or directory on disk inside one. */
final readonly class Holdable
{
    private function __construct(private Trees $trees, private Fingerprints $files)
    {
    }

    public static function in(Trees $trees, Fingerprints $files): self
    {
        return new self($trees, $files);
    }

    public function has(Path $path): bool
    {
        foreach ($this->trees as $tree) {
            if ($path->equals($tree->path()) || ($path->within($tree->path()) && $this->isOnDisk($path))) {
                return true;
            }
        }

        return false;
    }

    /** Whether a path is a file on disk, or a directory that holds one. */
    private function isOnDisk(Path $path): bool
    {
        foreach ($this->files as $file) {
            if ($file->path()->within($path)) {
                return true;
            }
        }

        return false;
    }
}
