<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * The coverage maps a plan handed a shard, each by the directory that holds
 * it: the shard's own, the lines of the files it mutates alone, which the
 * runner's run reads; the plan's whole map, which a runner reads only to
 * find the tests that run a line reading a value a mutant changes, wherever
 * that line is (ADR-0006); and, where the runner readies the map its shards
 * open on as the plan is made, where the plan may have left that (ADR-0163).
 */
final readonly class Handed
{
    private function __construct(private Path $own, private Path $whole, private Path|NotGiven $opened)
    {
    }

    public static function maps(Path $own, Path $whole): self
    {
        return new self($own, $whole, NotGiven::value());
    }

    /** These maps, with the directory the plan leaves the map its shards open on in, where it readies one. */
    public function openingOn(Path $opened): self
    {
        return new self($this->own, $this->whole, $opened);
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

    /** The directory the plan leaves the map its shards open on in, where it readies one; none where not told. */
    public function opened(): Path|NotGiven
    {
        return $this->opened;
    }
}
