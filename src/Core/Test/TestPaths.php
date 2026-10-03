<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use NightWorksIO\MutationGate\Core\File\Paths;

/**
 * Some test files, which a coverage run is narrowed to: the tests a change
 * moved, measured again while every other entry of the map is kept (ADR-0010,
 * decision 1; ADR-0023, decision 3).
 */
final readonly class TestPaths
{
    private function __construct(private Paths $files)
    {
    }

    /** The tests of these files, as paths from the project's root. */
    public static function of(Paths $files): self
    {
        return new self($files);
    }

    public function files(): Paths
    {
        return $this->files;
    }
}
