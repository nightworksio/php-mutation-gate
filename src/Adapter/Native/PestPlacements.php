<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Native;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Native\MutantPlacement;
use NightWorksIO\MutationGate\Core\Native\PlacesMutants;

/**
 * The native runner's placement of mutants in a project the Pest runner
 * serves (ADR-0148).
 *
 * @internal
 */
final readonly class PestPlacements implements PlacesMutants
{
    public function placementIn(string $root, Paths $tests, Path $vendor, Path $workspace): MutantPlacement
    {
        return Placement::inPestRunner($root, $tests, $vendor, $workspace);
    }
}
