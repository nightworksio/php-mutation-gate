<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Extension\Checks;

use function array_slice;
use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\Remeasured;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\CoverageRan;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Extension\LibraryChange;
use NightWorksIO\MutationGate\Extension\LibraryTest;
use NightWorksIO\MutationGate\Extension\RunnerLibrary;
use NightWorksIO\MutationGate\Port\Runner;

use function sprintf;

/**
 * What a runner measures of the library's whole suite: the test files that
 * judge a covered file and none for an uncovered one, the reports its run
 * left read back as its map, the same map across two processes, and test
 * files measured again, one or two at once, whose tests it places in them
 * and whose map, merged into the whole, is the whole.
 *
 * @internal the runner contract's own
 */
final readonly class RunnerCoverage
{
    /** Where each run's reports are left, under the library's root. */
    private const string WHOLE = '.mutation-gate/coverage';

    private const string ACROSS = '.mutation-gate/across';

    private const string AGAIN = '.mutation-gate/again';

    /** A file of the library no test covers, for it is not there. */
    private const string NOWHERE = 'src/Nowhere.php';

    /** How many test files are measured again at once, and how many processes measure them. */
    private const int AT_ONCE = 2;

    private function __construct(private Runner $runner, private RunnerLibrary $library, private CoverageMap $whole)
    {
    }

    /** @return list<string> */
    public static function failures(Runner $runner, RunnerLibrary $library): array
    {
        $whole = $runner->coverage(CoverageRun::of(WholeSuite::tests(), Path::of(self::WHOLE)));

        if ($whole instanceof CannotJudge) {
            return [sprintf('It measures no coverage of the whole suite: %s', $whole->why())];
        }

        $coverage = new self($runner, $library, $whole);

        return [
            ...$coverage->judges(),
            ...$coverage->readBack(),
            ...$coverage->across(),
            ...$coverage->again(),
        ];
    }

    /** @return list<string> */
    private function judges(): array
    {
        $money = LibraryChange::Adds->file();
        $judges = Records::paths($this->runner->judges($money, $this->whole));
        $expected = Records::paths(Paths::of(
            $this->library->testFile(LibraryTest::Drain),
            $this->library->testFile(LibraryTest::Money),
        ));
        $nowhere = Records::paths($this->runner->judges(Path::of(self::NOWHERE), $this->whole));

        return [
            ...Records::unless(
                $judges === $expected,
                sprintf(
                    'It names %s as the test files that judge %s, where %s do.',
                    $judges,
                    $money->value(),
                    $expected,
                ),
            ),
            ...Records::unless(
                $nowhere === '',
                sprintf('It names %s as test files that judge %s, which no test covers.', $nowhere, self::NOWHERE),
            ),
        ];
    }

    /** @return list<string> */
    private function readBack(): array
    {
        $read = $this->runner->coverage(CoverageRan::in(Path::of(self::WHOLE)));

        return Records::unless(
            Records::lines($read) === Records::lines($this->whole),
            'It reads the reports its own coverage run left as another map than that run measured.',
        );
    }

    /** @return list<string> */
    private function across(): array
    {
        $run = CoverageRun::of(WholeSuite::tests(), Path::of(self::ACROSS))->across(ProcessCount::of(self::AT_ONCE));
        $across = $this->runner->coverage($run);
        $tests = $across instanceof CoverageMap ? $across->tests() : $across;

        return Records::unless(
            Records::lines($across) === Records::lines($this->whole)
                && Records::tests($tests) === Records::tests($this->whole->tests()),
            'It measures another map across two processes than in one.',
        );
    }

    /** @return list<string> */
    private function again(): array
    {
        $files = $this->testFiles();
        $one = Paths::of(...array_slice([...$files], 0, 1));
        $two = Paths::of(...array_slice([...$files], 0, self::AT_ONCE));

        if (count($two) < self::AT_ONCE) {
            return ['It names the tests of its map in fewer than two test files.'];
        }

        $placed = $this->runner->testsIn($files, $this->whole);
        $heldByOne = $this->runner->testsIn($one, $this->whole);
        $againOne = $this->measured($one, ProcessCount::single());
        $againTwo = $this->measured($two, ProcessCount::of(self::AT_ONCE));
        $merged = $heldByOne instanceof TestIds && $againOne instanceof CoverageMap
            ? Remeasured::over($this->whole, $heldByOne, $againOne)
            : $againOne;

        return [
            ...Records::unless(
                $heldByOne instanceof TestIds && count($heldByOne) > 0
                    && $againOne instanceof CoverageMap && count($againOne->tests()) > 0,
                sprintf('It measures no test again of %s, whose tests its map holds.', Records::paths($one)),
            ),
            ...Records::unless(
                $placed instanceof TestIds && count($this->whole->tests()->without($placed)) === 0,
                'It places some tests of its map in none of the test files that hold them.',
            ),
            ...Records::unless(
                Records::lines($merged) === Records::lines($this->whole),
                'It measures a test file again into a map that, merged into the whole, is not the whole.',
            ),
            ...Records::unless(
                Records::tests($againTwo instanceof CoverageMap ? $againTwo->tests() : $againTwo)
                    === Records::tests($this->runner->testsIn($two, $this->whole)),
                'It measures two test files again at once into other tests than it places in them.',
            ),
        ];
    }

    /** The files of the tests of the whole map, by the names the runner gives them. */
    private function testFiles(): Paths
    {
        $names = $this->runner->names($this->whole->tests(), Withheld::standard());
        $files = Paths::none();

        foreach ($this->whole->tests() as $test) {
            $name = $names instanceof TestNames ? $names->testOf($test) : $test;
            $files = $name instanceof TestName ? $files->with($name->file()) : $files;
        }

        return $files;
    }

    private function measured(Paths $files, ProcessCount $processes): CoverageMap|CannotJudge
    {
        $run = CoverageRun::of(TestPaths::of($files), Path::of(self::AGAIN))->across($processes);

        return $this->runner->coverage($run);
    }
}
