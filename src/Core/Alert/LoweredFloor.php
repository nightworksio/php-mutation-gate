<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Alert;

use NightWorksIO\MutationGate\Core\Baseline\Lowered;
use NightWorksIO\MutationGate\Core\Baseline\Unlowered;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Floor;

/** A tree whose floor is below the floor the newest trend entry recorded, with why, where its baseline says. */
final readonly class LoweredFloor
{
    private function __construct(
        private Path $tree,
        private Floor $from,
        private Floor $to,
        private Lowered|Unlowered $lowering,
    ) {
    }

    public static function of(Path $tree, Floor $from, Floor $to, Lowered|Unlowered $lowering): self
    {
        return new self($tree, $from, $to, $lowering);
    }

    public function tree(): Path
    {
        return $this->tree;
    }

    /** The floor the newest trend entry recorded. */
    public function from(): Floor
    {
        return $this->from;
    }

    /** The floor the tree is held to now. */
    public function to(): Floor
    {
        return $this->to;
    }

    /** The baseline's `lowered`, whose reason says why; unlowered where the baseline gives none. */
    public function lowering(): Lowered|Unlowered
    {
        return $this->lowering;
    }
}
