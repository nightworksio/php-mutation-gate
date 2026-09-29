<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Tree;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;

/** A path whose code is judged whole, the floor it declares, and the package it belongs to. */
final readonly class Tree
{
    private function __construct(
        private Path $path,
        private Floor|Exempt|Undeclared $declared,
        private Package $package,
    ) {
    }

    public static function at(Path $path, Floor|Exempt|Undeclared $declared, Package $package): self
    {
        return new self($path, $declared, $package);
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
}
