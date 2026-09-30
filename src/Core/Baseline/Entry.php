<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Baseline;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Floor;

/** One tree's line of the baseline: its path, the floor it achieved, and why it was lowered where it was. */
final readonly class Entry
{
    private function __construct(private Path $tree, private Floor $floor, private Lowered|Unlowered $lowered)
    {
    }

    public static function of(Path $tree, Floor $floor): self
    {
        return new self($tree, $floor, Unlowered::floor());
    }

    /** This entry, with the reason its floor went down. */
    public function lowered(Lowered $lowered): self
    {
        return new self($this->tree, $this->floor, $lowered);
    }

    public function tree(): Path
    {
        return $this->tree;
    }

    public function floor(): Floor
    {
        return $this->floor;
    }

    public function lowering(): Lowered|Unlowered
    {
        return $this->lowered;
    }
}
