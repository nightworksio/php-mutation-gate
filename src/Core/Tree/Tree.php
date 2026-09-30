<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Tree;

use NightWorksIO\MutationGate\Core\File\Globs;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;

/**
 * A path whose code is judged whole, the floor it declares, the floor its new
 * lines are held to, the package it belongs to, and the files in it that
 * belong to no tree (ADR-0016).
 */
final readonly class Tree
{
    private function __construct(
        private Path $path,
        private Floor|Exempt|Undeclared $declared,
        private Package $package,
        private Floor|Undeclared $newCode,
        private Globs $excluded,
    ) {
    }

    public static function at(Path $path, Floor|Exempt|Undeclared $declared, Package $package): self
    {
        return new self($path, $declared, $package, Undeclared::floor(), Globs::of());
    }

    /** This tree, with the floor its manifest declares for new lines in place of `newCode.floor`. */
    public function withNewCodeFloor(Floor $floor): self
    {
        return clone($this, ['newCode' => $floor]);
    }

    /** This tree, declaring this floor, or its exemption and why, in place of its own. */
    public function declaring(Floor|Exempt|Undeclared $declared): self
    {
        return clone($this, ['declared' => $declared]);
    }

    /** This tree, whose files these globs match, from the repository root, belong to no tree. */
    public function excluding(Globs $excluded): self
    {
        return clone($this, ['excluded' => $excluded]);
    }

    /** Whether a file belongs to no tree by this tree's word. */
    public function excludes(Path $file): bool
    {
        return $this->excluded->matches($file);
    }

    public function path(): Path
    {
        return $this->path;
    }

    public function declared(): Floor|Exempt|Undeclared
    {
        return $this->declared;
    }

    public function package(): Package
    {
        return $this->package;
    }

    /** The floor for new lines in this tree, or undeclared where `newCode.floor` applies. */
    public function newCodeFloor(): Floor|Undeclared
    {
        return $this->newCode;
    }
}
