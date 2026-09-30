<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;

/** Which tests ran the lines of a file, as a mutation run's opening map says. */
interface Covering
{
    /** @return list<string> the tests that ran any line from the first to the last of a file on disk, each once */
    public function testsCovering(string $file, int $first, int $last): array;

    /** The map, with each file as the project spells it. */
    public function map(Project $project): CoverageMap;
}
