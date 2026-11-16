<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Native;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

/**
 * What gives a runner's project the placement of its mutants (ADR-0148): the
 * override, each mutant's files and the files beside them, at the project's
 * root, tests, vendor and gate directories.
 *
 * @internal
 */
interface PlacesMutants
{
    /** The placement of mutants in the project at this root, with these tests, vendor and gate directories. */
    public function placementIn(string $root, Paths $tests, Path $vendor, Path $workspace): MutantPlacement;
}
