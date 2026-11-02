<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Testo;

use function array_map;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Native\CoverageReport;
use NightWorksIO\MutationGate\Core\OwnScript;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

use function sprintf;

/**
 * The gate's Testo launcher's command line, as the native runner starts it
 * (ADR-0027): the launcher, the project's autoloader, what the gate tells
 * the launcher (see LaunchOption), then Testo's own `run` options, with no
 * colour.
 *
 * - For one mutant: only the covering tests selected, in the group or
 *   matching the filter that judges the unit, and stopped after the first
 *   test that fails unless every killer is asked for.
 * - For a run of no test, timing a mutant's start-up: as for a mutant,
 *   narrowed to no test, which passes with none run.
 * - For a coverage map: the tests the run asks for in the suites it asks
 *   for, their map written as Testo's XML coverage, and each one's time.
 * - For the suites' tests: listed into a file, and none run.
 */
final readonly class CommandLine
{
    /** Testo's own options, after the launcher's. */
    private const array RUN = ['--no-ansi'];

    /** What has Testo write PHPUnit's XML coverage into a directory. */
    private const string XML = '--coverage-xml=%s';

    /** What keeps Testo to one suite; given once for each. */
    private const string SUITE = '--suite=%s';

    /** What the launcher is told for a run that stops after the first failure. */
    private const string STOPS = 'after-the-first-failure';

    public function __construct(private OwnScript $launcher, private string $autoloader)
    {
    }

    /** @return list<string> a mutant's run that selects its tests so, judged as the request asks */
    public function mutant(string $selection, MutationRequest $request): array
    {
        return $this->run($selection, $request->search()->matrix(), $this->judgedBy($request->judgedBy()));
    }

    /** @return list<string> a run of no test, started as a mutant's own run is, passing with none run */
    public function startUp(string $selection): array
    {
        return $this->run($selection, MatrixKind::FirstKiller, $this->judgedBy(Filter::nothing()));
    }

    /** @return list<string> the tests a coverage run asks for, their XML coverage written into this directory */
    public function coverage(CoverageRun $request, string $map): array
    {
        return [
            ...$this->launcher->started(),
            $this->autoloader,
            LaunchOption::Times->with(CoverageReport::timesBeside($map)),
            ...$this->covering($request->tests()),
            ...self::RUN,
            sprintf(self::XML, $map),
            ...$this->in($request->suites()),
        ];
    }

    /** @return list<string> these suites' tests and their groups, listed into this file, and none run */
    public function listing(Suites $suites, string $file): array
    {
        return [
            ...$this->launcher->started(),
            $this->autoloader,
            LaunchOption::Listing->with($file),
            ...self::RUN,
            ...$this->in($suites),
        ];
    }

    /**
     * @param list<string> $options
     *
     * @return list<string> the launcher with its selection, stopped after the first failure unless the matrix is full
     */
    private function run(string $selection, MatrixKind $matrix, array $options): array
    {
        return [
            ...$this->launcher->started(),
            $this->autoloader,
            $selection,
            ...$options,
            ...$matrix === MatrixKind::Full ? [] : [LaunchOption::Stop->with(self::STOPS)],
            ...self::RUN,
        ];
    }

    /** @return list<string> what narrows a coverage run: a group, a filter, or the test files it runs */
    private function covering(WholeSuite|Group|Filter|TestPaths $tests): array
    {
        return $tests instanceof TestPaths
            ? array_map(static fn(Path $file): string => LaunchOption::File->with($file->value()), [...$tests->files()])
            : $this->judgedBy($tests);
    }

    /** @return list<string> the options that keep a run to the tests that judge the unit */
    private function judgedBy(WholeSuite|Group|Filter $judgedBy): array
    {
        return match (true) {
            $judgedBy instanceof Group => [LaunchOption::Group->with($judgedBy->name())],
            $judgedBy instanceof Filter => [LaunchOption::Filter->with($judgedBy->pattern())],
            default => [],
        };
    }

    /** @return list<string> Testo's options that keep a run to these suites; none for every suite */
    private function in(Suites $suites): array
    {
        return $suites->isAll()
            ? []
            : array_map(static fn(SuiteName $suite): string => sprintf(self::SUITE, $suite->value()), [...$suites]);
    }
}
