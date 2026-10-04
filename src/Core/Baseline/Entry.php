<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Baseline;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Floor;

/**
 * One line of the baseline: the path of a tree, or of a package for its
 * security set, the floor it achieved, and why it was lowered where it was.
 */
final readonly class Entry
{
    private function __construct(private Path $path, private Floor $floor, private Lowered|Unlowered $lowered)
    {
    }

    public static function of(Path $path, Floor $floor): self
    {
        return new self($path, $floor, Unlowered::floor());
    }

    /** This entry, with the reason its floor went down. */
    public function lowered(Lowered $lowered): self
    {
        return new self($this->path, $this->floor, $lowered);
    }

    /** The tree, or the package whose security set, it holds a floor for. */
    public function path(): Path
    {
        return $this->path;
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
