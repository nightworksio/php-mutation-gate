<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestCases;

/**
 * The map a runner's shard opens on where it readies more than the map the
 * plan hands it, readied once as the plan is made, for the files of every
 * shard at once (ADR-0163).
 */
interface ShardOpening
{
    /**
     * The map shards of some of these files open on, readied from the plan's
     * whole map, with the files the suite runs as files of test cases; none
     * where a shard opens on the map it is handed as it is.
     */
    public function readied(CoverageMap $whole, Paths $files, TestCases $cases): CoverageMap|NotGiven|CannotJudge;
}
