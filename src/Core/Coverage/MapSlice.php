<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

/**
 * One map a plan hands over beside its whole map (ADR-0118): where it is
 * written, and the files whose lines and methods it holds, with every test of
 * the whole map, as the map `onlyFor()` those files writes it.
 */
final readonly class MapSlice
{
    private function __construct(private Path $into, private Paths $files)
    {
    }

    public static function of(Path $into, Paths $files): self
    {
        return new self($into, $files);
    }

    /** Where the slice is written, in the project. */
    public function into(): Path
    {
        return $this->into;
    }

    /** The files whose lines and methods it holds. */
    public function files(): Paths
    {
        return $this->files;
    }
}
