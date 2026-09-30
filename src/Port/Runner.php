<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Port;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Runner\CoverageRequest;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * What mutates the code: Pest, Infection, or a runner an extension adds. A
 * failed opening run, an unsupported version or a result that does not add
 * up is answered as cannot judge, with the runner's output.
 */
interface Runner
{
    /**
     * The runner's name, the exact version of every package it drives, and a
     * digest of the PHP it runs on, as that PHP describes itself when started
     * the way the runner starts it, never seeing the variables withheld.
     */
    public function identity(Withheld $withheld): Identity|CannotJudge;

    /**
     * The suite's groups, as the runner itself lists them. Listing loads the
     * project's code, which never sees the variables withheld.
     */
    public function groups(Withheld $withheld): Groups|CannotJudge;

    /**
     * Which tests run which line, with each test's duration, by running the
     * suite or a group, or by reading a map another job wrote.
     */
    public function coverage(CoverageRequest $request): CoverageMap|CannotJudge;

    /** The test files that can judge a mutant of this file, by the runner's own rules for selecting them. */
    public function judges(Path $file, CoverageMap $map): Paths|CannotJudge;

    /**
     * Every mutant's result for the requested files, judged by the tests the
     * request names, and how many were skipped with no record.
     */
    public function mutate(MutationRequest $request): MutationResult|CannotJudge;

    /**
     * These mutants run again, each allowed this long and judged by the tests
     * that judged their unit, matched back by the gate's id. The tests never
     * see the variables withheld.
     */
    public function retry(
        Mutants $mutants,
        Seconds $limit,
        WholeSuite|Group|Filter $judgedBy,
        Withheld $withheld,
    ): Mutants|CannotJudge;

    /**
     * The runner's own ignore markers in these files and in its config, each
     * with the `ignores.entries` entry that replaces it. Whether a run may go
     * ahead with them is the verdict's to decide (ADR-0008).
     */
    public function markers(Paths $files): Markers|CannotJudge;

    /**
     * The files that define how the runner runs, such as its own config and
     * the PHPUnit config it runs with, as paths from the project's root: a
     * change to one reaches everything, and every content key reads them.
     */
    public function definitions(): Paths;

    /**
     * What each of these tests is: the whole test, by its file and the
     * description the runner gives it, or the data set row that folds into
     * it. An id the runner names nothing is left out. Naming may load the
     * project's code, which never sees the variables withheld (ADR-0014).
     */
    public function names(TestIds $tests, Withheld $withheld): TestNames|CannotJudge;

    /**
     * The runner in a package's directory, as a path from the project's
     * root: its tests, its vendor and the gate's directory are the package's.
     * A directory that holds no project the runner can run cannot be judged
     * (ADR-0005).
     */
    public function rootedAt(Path $package): self|CannotJudge;
}
