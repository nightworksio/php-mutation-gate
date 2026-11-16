<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestCases;

/**
 * A shard that opens on the map it is handed as it is, with nothing readied beside it.
 *
 * @internal
 */
final readonly class HandedAsIs implements ShardOpening
{
    public function readied(CoverageMap $whole, Paths $files, TestCases $cases): NotGiven
    {
        return NotGiven::value();
    }
}
