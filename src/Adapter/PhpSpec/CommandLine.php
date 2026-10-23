<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpSpec;

use function array_map;
use function array_values;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

/**
 * The gate's PhpSpec launcher's command line, as the native runner starts
 * it (ADR-0027): the launcher, the project's autoloader, what the gate tells
 * the launcher (see LaunchOption), then PhpSpec's own `run`, which neither
 * asks nor generates code, and prints a dot for each example.
 *
 * - For one mutant: only the covering examples selected, in the group or
 *   matching the filter that judges the unit, and stopped at the first
 *   example that fails or breaks unless every killer is asked for.
 * - For a run of no test, timing a mutant's start-up: as for a mutant,
 *   narrowed to no example, which passes with none run.
 * - For a coverage map: the examples the run asks for, with their map
 *   written to a file.
 * - For the suite's examples: listed into a file, and none run.
 */
final readonly class CommandLine
{
    /** PhpSpec's own arguments, after the launcher's. */
    private const array RUN = ['run', '--no-interaction', '--no-code-generation', '--no-rerun', '--format=dot'];

    /** What stops PhpSpec at the first example that fails or breaks. */
    private const string STOP = '--stop-on-failure';

    public function __construct(private string $launcher, private string $autoloader)
    {
    }

    /** @return list<string> a mutant's run that selects its examples so, judged as the request asks */
    public function mutant(string $selection, MutationRequest $request): array
    {
        return $this->run($selection, $request->search()->matrix(), ...$this->judgedBy($request->judgedBy()));
    }

    /** @return list<string> a run of no example, started as a mutant's own run is, passing with none run */
    public function startUp(string $selection): array
    {
        return $this->run($selection, MatrixKind::FirstKiller, ...$this->judgedBy(Filter::nothing()));
    }

    /** @return list<string> the examples a coverage run asks for, their map written to this file */
    public function coverage(CoverageRun $request, string $map): array
    {
        return [
            $this->launcher,
            $this->autoloader,
            LaunchOption::Coverage->with($map),
            ...$this->covering($request->tests()),
            ...self::RUN,
        ];
    }

    /** @return list<string> the suite's examples and their groups, listed into this file, and none run */
    public function listing(string $file): array
    {
        return [$this->launcher, $this->autoloader, LaunchOption::Listing->with($file), ...self::RUN];
    }

    /** @return list<string> the launcher with its selection, stopped at the first failure unless the matrix is full */
    private function run(string $selection, MatrixKind $matrix, string ...$options): array
    {
        return [
            $this->launcher,
            $this->autoloader,
            $selection,
            ...array_values($options),
            ...self::RUN,
            ...$matrix === MatrixKind::Full ? [] : [self::STOP],
        ];
    }

    /** @return list<string> what narrows a coverage run: a group, a filter, or the spec files it runs */
    private function covering(WholeSuite|Group|Filter|TestPaths $tests): array
    {
        return $tests instanceof TestPaths
            ? array_map(static fn(Path $file): string => LaunchOption::Spec->with($file->value()), [...$tests->files()])
            : $this->judgedBy($tests);
    }

    /** @return list<string> the options that keep a run to the examples that judge the unit */
    private function judgedBy(WholeSuite|Group|Filter $judgedBy): array
    {
        return match (true) {
            $judgedBy instanceof Group => [LaunchOption::Group->with($judgedBy->name())],
            $judgedBy instanceof Filter => [LaunchOption::Filter->with($judgedBy->pattern())],
            default => [],
        };
    }
}
