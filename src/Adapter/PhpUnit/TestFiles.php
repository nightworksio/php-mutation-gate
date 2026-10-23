<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function count;
use function file_get_contents;
use function is_file;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Native\TestLayout;
use NightWorksIO\MutationGate\Core\Php\DependsReader;
use NightWorksIO\MutationGate\Core\Test\TestClassFiles;
use NightWorksIO\MutationGate\Core\Test\TestDependencies;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Test\TestsByClass;

use function sprintf;

/**
 * The files in the project's test directories that declare some test
 * classes, found by their tokens. The directories are walked once, and a file
 * is read only where it is named after a class asked about.
 */
final class TestFiles implements TestLayout
{
    /** @var list<list<Path>> every PHP file in the test directories, once walked */
    private array $walked = [];

    /** @var list<array{CoverageMap, TestsByClass}> the tests of the map last asked about, by class, once read */
    private array $byClass = [];

    public function __construct(private readonly Installation $installation)
    {
    }

    /** The files that declare the classes of these tests. */
    public function declaring(TestIds $tests): TestClassFiles
    {
        $classes = TestClassFiles::of($tests);

        foreach ($this->all() as $file) {
            $classes = $classes->mayDeclare($file) ? $classes->readIn($file, $this->contentsOf($file)) : $classes;
        }

        return $classes;
    }

    /** These tests and every test they depend on, in turn, as the files of their classes record it. */
    public function withDependencies(TestIds $tests): TestIds
    {
        return TestDependencies::closure(
            $tests,
            fn(TestIds $asked): TestDependencies => DependsReader::inFiles(
                $this->declaring($asked)->files(),
                $this->contentsOf(...),
            ),
        );
    }

    /**
     * Those of a map's tests that these test files hold: the tests of each
     * class a file declares, and of each class named after a file that is gone.
     */
    public function testsIn(Paths $files, CoverageMap $map): TestIds
    {
        return TestClassFiles::held($this->testsOf($map), $files, $this->held(...));
    }

    /**
     * The files that declare the classes of the tests that cover the file:
     * the files a mutant's run selects where it cannot select a test by its
     * id.
     */
    public function judges(Path $file, CoverageMap $map): Paths
    {
        $covering = $map->testsCoveringFile($file);

        return count($covering) === 0 ? Paths::none() : $this->declaring($covering)->files();
    }

    /** Each test by the file that declares its class and its method's name. */
    public function names(TestIds $tests): TestNames
    {
        return $this->declaring($tests)->names($tests);
    }

    /** The map's tests by class, read once while it is the map asked about. */
    private function testsOf(CoverageMap $map): TestsByClass
    {
        if ($this->byClass === [] || $this->byClass[0][0] !== $map) {
            $this->byClass = [[$map, TestsByClass::of($map->tests())]];
        }

        return $this->byClass[0][1];
    }

    /** What a test file holds, or that it is gone. */
    private function held(Path $file): Contents|Missing
    {
        return is_file($this->installation->absolute($file)) ? $this->contentsOf($file) : Missing::at($file);
    }

    private function contentsOf(Path $file): Contents
    {
        return Contents::of(sprintf('%s', file_get_contents($this->installation->absolute($file))));
    }

    /** @return list<Path> */
    private function all(): array
    {
        if ($this->walked === []) {
            $this->walked = [PhpFiles::in($this->installation, $this->installation->tests(), Paths::none())];
        }

        return $this->walked[0];
    }
}
