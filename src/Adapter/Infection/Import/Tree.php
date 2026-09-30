<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection\Import;

use NightWorksIO\MutationGate\Core\Config\DeclaredTree;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;

/** A tree an import declares: its path, an exemption zero-config found for it, and the globs it excludes. */
final readonly class Tree
{
    /** @param list<string> $excludes */
    private function __construct(private Path $path, private Exempt|Undeclared $found, private array $excludes)
    {
    }

    /** A tree Infection's config names, which nothing exempts. */
    public static function named(Path $path): self
    {
        return new self($path, Undeclared::floor(), []);
    }

    /** A tree zero-config found, exempt where it found it so. */
    public static function found(DeclaredTree $tree): self
    {
        $declared = $tree->declared();

        return new self($tree->path(), $declared instanceof Exempt ? $declared : Undeclared::floor(), []);
    }

    public function path(): Path
    {
        return $this->path;
    }

    /** This tree, also excluding what a glob matches. */
    public function excluding(string $glob): self
    {
        return new self($this->path, $this->found, [...$this->excludes, $glob]);
    }

    public function excludes(): bool
    {
        return $this->excludes !== [];
    }

    /** The tree as the config declares it: at the imported floor, unless zero-config found it exempt. */
    public function declared(Floor|Undeclared $floor): DeclaredTree
    {
        return DeclaredTree::of(
            $this->path,
            $this->found instanceof Exempt ? $this->found : $floor,
            Listed::of(...$this->excludes),
        );
    }
}
