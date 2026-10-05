<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

/**
 * What a coverage run is for, which decides what it writes: a mutation run,
 * which reads only the layout Infection's `--coverage` reads, or the gate's
 * map, which also reads the lines no test ran from the report beside it.
 */
enum CoverageFor
{
    case Mutation;
    case Map;
}
