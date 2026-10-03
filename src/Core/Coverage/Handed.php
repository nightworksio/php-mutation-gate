<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use NightWorksIO\MutationGate\Core\File\Path;

/**
 * The coverage maps a plan handed a shard, each by the directory that holds
 * it: the shard's own, the lines of the files it mutates alone, which the
 * runner's run reads; and the plan's whole map, which a runner reads only to
 * find the tests that run a line reading a value a mutant changes, wherever
 * that line is (ADR-0006).
 */
final readonly class Handed
{
    private function __construct(private Path $own, private Path $whole)
    {
    }

    public static function maps(Path $own, Path $whole): self
    {
        return new self($own, $whole);
    }

    /** The directory of the shard's own map. */
    public function own(): Path
    {
        return $this->own;
    }

    /** The directory of the plan's whole map. */
    public function whole(): Path
    {
        return $this->whole;
    }
}
