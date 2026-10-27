<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Native;

use NightWorksIO\MutationGate\Core\Assertion\AssertionStyle;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\OwnScript;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\ErrorDisplay;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Program;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;

/**
 * A test framework the gate runs its own mutants with (ADR-0023, ADR-0027):
 * what its runs read and are started with, and where its tests are. The
 * native runner serves each mutant through the override and records each
 * run, whatever the framework.
 */
interface Framework
{
    /** The program it is, which names it wherever the gate speaks of it. */
    public function program(): Program;

    /** The runner a config names to run it natively. */
    public function runner(): BuiltinRunner;

    /** The exact version of every package the runner drives, as Composer installed it; or why it cannot drive them. */
    public function versions(): Versions|CannotJudge;

    /** Whether the framework is installed in the project's vendor directory. */
    public function isInstalled(): bool;

    /** The framework in a package's directory, as a path from the project's root, its tests laid out so. */
    public function in(Path $package, TestLayout $tests): self;

    /** The files that define how it runs, as paths from the project's root. */
    public function definitions(): Paths;

    /** Where its config has PHP print errors; none where it sets nothing, or there is no config. */
    public function errorDisplay(): ErrorDisplay|NotGiven;

    /** Its config, by its absolute path, which a warm worker boots with; none where the project has none. */
    public function config(): string|NotGiven;

    /** The script a mutant's run starts, by its absolute path, from which a warm worker's child reads its arguments. */
    public function script(): string;

    /** The gate's script a warm worker runs; none where the framework has no warm workers. */
    public function workerScript(): OwnScript|NotGiven;

    /** How a mutant's run selects these tests; or why no test file places them. */
    public function selection(TestIds $tests): Selection|Reason;

    /**
     * The arguments after PHP's own of a mutant's run that selects its tests
     * with this option, judged as the request asks, stopping at the first
     * test that fails unless the request asks for every killer.
     *
     * @return list<string>
     */
    public function mutant(string $selection, MutationRequest $request): array;

    /**
     * The arguments of a run of no test, started as a mutant's own run is,
     * passing with none run.
     *
     * @return list<string>
     */
    public function startUp(string $selection): array;

    /**
     * The arguments, after PHP's options for collecting coverage, of the run
     * of the tests a coverage run asks for, writing their map to this file.
     *
     * @return list<string>
     */
    public function coverage(CoverageRun $request, string $map): array;

    /** These suites' tests and the groups each is in, listed by the framework into this file and none run. */
    public function listing(Shell $shell, Withheld $withheld, Suites $suites, string $file): TestListing|CannotJudge;

    /**
     * Whether this output of a run says its process ended mid-test where
     * PHP's errors were visibly hidden: as the framework says it hid them, or
     * where the project's config hides them and the framework says the
     * process ended.
     */
    public function endedHidden(string $output, bool $configuredToHide): bool;

    /** Where its tests are. */
    public function tests(): TestLayout;

    /** The style its tests are written in, as a stub of one is; none where it is no style a stub is written in. */
    public function testStyle(): AssertionStyle|NotGiven;
}
