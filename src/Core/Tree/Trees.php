<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Tree;

use function array_any;
use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Config\DeclaredTree;
use NightWorksIO\MutationGate\Core\File\Globs;
use NightWorksIO\MutationGate\Core\File\Path;
use Traversable;

/**
 * Trees, in the order they were found.
 *
 * @implements IteratorAggregate<int, Tree>
 */
final readonly class Trees implements Countable, IteratorAggregate
{
    /** @param list<Tree> $trees */
    private function __construct(private array $trees)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(Tree ...$trees): self
    {
        return new self(array_values($trees));
    }

    public function with(Tree $tree): self
    {
        return new self([...$this->trees, $tree]);
    }

    /**
     * These trees, with the config's trees laid over them (ADR-0003): a tree
     * of a declared path takes the floor, or the exemption, and the excluded
     * files the config declares for it, keeping its package; a declared path
     * no tree has becomes a tree of the package that holds it.
     */
    public function declaring(DeclaredTree ...$declared): self
    {
        $trees = $this->trees;

        foreach ($declared as $entry) {
            $trees = $this->laid($trees, $entry);
        }

        return new self($trees);
    }

    /**
     * The tree a path is in: the innermost one whose path holds it. A file a
     * tree excludes is in none.
     */
    public function holding(Path $path): Tree|Outside
    {
        if (array_any($this->trees, static fn(Tree $tree): bool => $tree->excludes($path))) {
            return Outside::trees();
        }

        $holding = Outside::trees();

        foreach ($this->trees as $tree) {
            $inner = $holding instanceof Outside || $tree->path()->within($holding->path());
            $holding = $inner && $path->within($tree->path()) ? $tree : $holding;
        }

        return $holding;
    }

    public function count(): int
    {
        return count($this->trees);
    }

    /** @return Traversable<int, Tree> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->trees);
    }

    /**
     * @param  list<Tree> $trees
     * @return list<Tree>
     */
    private function laid(array $trees, DeclaredTree $entry): array
    {
        $excluded = Globs::of(...$entry->exclude());

        foreach ($trees as $at => $tree) {
            if ($tree->path()->value() === $entry->path()->value()) {
                $trees[$at] = $tree->declaring($entry->declared())->excluding($excluded);

                return $trees;
            }
        }

        $holding = new self($trees)->holding($entry->path());
        $package = $holding instanceof Tree ? $holding->package() : Package::at(Path::root());

        return [...$trees, Tree::at($entry->path(), $entry->declared(), $package)->excluding($excluded)];
    }
}
