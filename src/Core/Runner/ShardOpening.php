<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\MutantSites;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestCases;

/**
 * What a runner readies once as the plan is made, for the files of every
 * shard at once: the map a shard opens on where it readies more than the map
 * the plan hands it (ADR-0163), and every mutant of each file, made whole,
 * where a part of a cut run numbers its mutants by them (ADR-0004).
 */
interface ShardOpening
{
    /**
     * The map shards of some of these files open on, readied from the plan's
     * whole map, with the files the suite runs as files of test cases; none
     * where a shard opens on the map it is handed as it is.
     */
    public function readied(CoverageMap $whole, Paths $files, TestCases $cases): CoverageMap|NotGiven|CannotJudge;

    /**
     * Where each mutant the runner makes of each of these whole files starts,
     * made once and kept for every shard of the plan to number its own by
     * (ADR-0004); none where a run numbers its mutants of a file alike
     * whichever of them it makes, or of a file it cannot make.
     */
    public function made(Paths $files, Mutators $mutators, Withheld $withheld): MutantSites|CannotJudge;
}
