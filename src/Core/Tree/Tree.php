<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Tree;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;

/**
 * A path whose code is judged whole, the floor it declares, the floor its new
 * lines are held to, and the package it belongs to.
 */
final readonly class Tree
{
    private function __construct(
        private Path $path,
        private Floor|Exempt|Undeclared $declared,
        private Package $package,
        private Floor|Undeclared $newCode,
    ) {
    }

    public static function at(Path $path, Floor|Exempt|Undeclared $declared, Package $package): self
    {
        return new self($path, $declared, $package, Undeclared::floor());
    }

    /** This tree, with the floor its manifest declares for new lines in place of `newCode.floor`. */
    public function withNewCodeFloor(Floor $floor): self
    {
        return new self($this->path, $this->declared, $this->package, $floor);
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
