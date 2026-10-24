<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function array_map;
use function array_values;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

use function sprintf;

/**
 * PHPUnit's command line, as the native runner starts it (ADR-0023).
 *
 * - For one mutant: the extension recording each test; only the covering
 *   tests selected; and stopped at the first test that fails or errors, and
 *   not at one that is only risky or warns. It collects no coverage, writes
 *   none of the project's logs and leaves PHPUnit's test run history as it
 *   was, whatever the project's config asks, with the option the installed
 *   PHPUnit names that by, so a project that fails on PHPUnit's own
 *   deprecations runs.
 * - For a run of no test, timing a mutant's start-up: as for a mutant,
 *   narrowed to no test, and passing with none run.
 * - For a coverage map: the tests the run asks for, with their map written as
 *   `--coverage-php` writes it, and neither the project's logs nor the test
 *   run history written.
 * - For the suite's groups: listed into a file, without colour, and no test
 *   run.
 *
 * Each keeps PHPUnit's caches in the gate's own directory for them, whatever
 * the project's config names, so no run writes a file a content key reads
 * (ADR-0007).
 */
final readonly class CommandLine
{
    /**
     * @param string        $phpunit PHPUnit's script, by its absolute path
     * @param PhpUnitOption $history the option that leaves the test run history as it was, as the installed PHPUnit
     *                               names it
     * @param string        $cache   where PHPUnit keeps its caches, the gate's own directory for them, by its absolute
     *                               path
     */
    public function __construct(private string $phpunit, private PhpUnitOption $history, private string $cache)
    {
    }

    /**
     * @return list<string> a mutant's run that selects its tests with this
     *                      option, judged as the request asks
     */
    public function mutant(string $selection, MutationRequest $request): array
    {
        $suites = $request->narrowing()->suitesForMutants($request->judgedBy(), $request->coverage());

        return $this->run(
            $selection,
            $request->search()->matrix(),
            ...$this->judgedBy($request->judgedBy()),
            ...PhpUnitOption::inSuites($suites),
        );
    }

    /** @return list<string> a run of no test, started as a mutant's own run is, passing with none run */
    public function startUp(string $selection): array
    {
        return $this->run(
            $selection,
            MatrixKind::FirstKiller,
            ...$this->judgedBy(Filter::nothing()),
            ...[PhpUnitOption::DoNotFailOnEmptyTestSuite->value],
        );
    }

    /** @return list<string> the tests a coverage run asks for, their map written to this file */
    public function coverage(CoverageRun $request, string $map): array
    {
        return [
            $this->phpunit,
            sprintf('%s=%s', PhpUnitOption::CoveragePhp->value, $map),
            PhpUnitOption::NoLogging->value,
            $this->history->value,
            PhpUnitOption::caching($this->cache),
            PhpUnitOption::NoProgress->value,
            ...$this->covering($request->tests()),
            ...PhpUnitOption::inSuites($request->suites()),
        ];
    }

    /** @return list<string> these suites' tests and their groups, listed into this file, and no test run */
    public function listing(Suites $suites, string $file): array
    {
        return [
            $this->phpunit,
            sprintf('%s=%s', PhpUnitOption::ListTestsXml->value, $file),
            PhpUnitOption::NoColors->value,
            PhpUnitOption::caching($this->cache),
            ...PhpUnitOption::inSuites($suites),
        ];
    }

    /**
     * @return list<string> PHPUnit with the extension and its selection,
     *                      stopped at the first test that fails or errors
     *                      unless the matrix is full, with these options after
     */
    private function run(string $selection, MatrixKind $matrix, string ...$options): array
    {
        $stops = $matrix === MatrixKind::Full
            ? []
            : [PhpUnitOption::StopOnError->value, PhpUnitOption::StopOnFailure->value];

        return [
            $this->phpunit,
            PhpUnitOption::Extension->value,
            Extension::class,
            $selection,
            ...$stops,
            PhpUnitOption::NoCoverage->value,
            PhpUnitOption::NoLogging->value,
            $this->history->value,
            PhpUnitOption::caching($this->cache),
            PhpUnitOption::NoProgress->value,
            ...array_values($options),
        ];
    }

    /** @return list<string> what narrows a coverage run: a group, a filter, or the test files run in place of suites */
    private function covering(WholeSuite|Group|Filter|TestPaths $tests): array
    {
        return $tests instanceof TestPaths
            ? array_map(static fn(Path $file): string => $file->value(), [...$tests->files()])
            : $this->judgedBy($tests);
    }

    /** @return list<string> the options that keep a run to the tests that judge the unit */
    private function judgedBy(WholeSuite|Group|Filter $judgedBy): array
    {
        return match (true) {
            $judgedBy instanceof Group => [PhpUnitOption::Group->value, $judgedBy->name()],
            $judgedBy instanceof Filter => [PhpUnitOption::Filter->value, $judgedBy->pattern()],
            default => [],
        };
    }
}
