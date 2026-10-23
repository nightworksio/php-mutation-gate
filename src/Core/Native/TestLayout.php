<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Native;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\TestClassFiles;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestNames;

/** Where a framework's tests are, by its own rules for naming a test after its file. */
interface TestLayout
{
    /**
     * The tests of a map that these test files hold: the entries a run of
     * those files measures again. A test the framework cannot place in a
     * file is not among them.
     */
    public function testsIn(Paths $files, CoverageMap $map): TestIds;

    /** The test files a mutant's run of this file selects where it cannot select a test by its id. */
    public function judges(Path $file, CoverageMap $map): Paths;

    /** Each test by its file and the description the framework gives it. */
    public function names(TestIds $tests): TestNames;

    /** The files that declare the classes of these tests. */
    public function declaring(TestIds $tests): TestClassFiles;

    /** These tests and every test they depend on, in turn. */
    public function withDependencies(TestIds $tests): TestIds;
}
