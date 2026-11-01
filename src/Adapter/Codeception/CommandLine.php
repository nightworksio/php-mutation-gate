<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Codeception;

use function array_map;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

use function sprintf;
use function str_replace;

/**
 * Codeception's command line, as the native runner starts it (ADR-0027):
 * `codecept run`, which asks nothing and prints no colour, with the gate's
 * extension enabled and its settings set (see Option).
 *
 * - For one mutant: only the covering tests selected, in the group or
 *   matching the filter that judges the unit, and stopped at the first test
 *   that fails or errors unless every killer is asked for.
 * - For a run of no test, timing a mutant's start-up: as for a mutant,
 *   narrowed to no test, which passes with none run.
 * - For a coverage map: the tests the run asks for, with their map written
 *   to a file.
 * - For the suite's tests: listed into a file, and none run.
 *
 * @internal
 */
final readonly class CommandLine
{
    /** Codeception's own arguments, before the extension's. */
    private const array RUN = ['run', '--no-interaction', '--no-colors'];

    /** What enables an extension for one run. */
    private const string EXTENSION = '--ext';

    /** What sets a setting of Codeception's config for one run. */
    private const string OVERRIDE = '--override';

    /** The extension's settings, as an override sets them: its class's, as JSON, which YAML reads. */
    private const string SETTINGS = 'extensions: config: %s: %s';

    /** What Codeception reads as the end of a key in an override, which no value may hold. */
    private const string KEY_END = ': ';

    /** The same text inside a JSON string. */
    private const string KEY_END_IN_TEXT = ':\u0020';

    /** The memory_limit Codeception sets its own process to, as an override sets it. */
    private const string MEMORY = 'settings: memory_limit: %s';

    /** What stops Codeception at the first test that fails or errors. */
    private const string STOP = '--fail-fast';

    public function __construct(private string $codecept, private string $vendor)
    {
    }

    /**
     * @return list<string> a mutant's run that selects its tests from this file, judged as the request asks, under
     *                      the request's memory cap, which Codeception would otherwise replace with its own limit
     */
    public function mutant(string $selection, MutationRequest $request): array
    {
        $memory = $request->memory();

        return [
            ...$this->run(
                [Option::Selected->value => $selection, ...$this->judgedBy($request->judgedBy())],
                $request->search()->matrix(),
            ),
            ...$memory->caps() ? [self::OVERRIDE, sprintf(self::MEMORY, $memory->written())] : [],
        ];
    }

    /** @return list<string> a run of no test, started as a mutant's own run is, passing with none run */
    public function startUp(string $selection): array
    {
        return $this->run(
            [Option::Selected->value => $selection, ...$this->judgedBy(Filter::nothing())],
            MatrixKind::FirstKiller,
        );
    }

    /** @return list<string> the tests a coverage run asks for, their map written to this file */
    public function coverage(CoverageRun $request, string $map): array
    {
        return $this->started([
            Option::Coverage->value => $map,
            Option::Vendor->value => $this->vendor,
            ...$this->covering($request->tests()),
        ]);
    }

    /** @return list<string> the suite's tests and their groups, listed into this file, and none run */
    public function listing(string $file): array
    {
        return $this->started([Option::Listing->value => $file]);
    }

    /**
     * @param array<string, string|list<string>> $told
     *
     * @return list<string> a run with these settings, stopped at the first failure unless the matrix is full
     */
    private function run(array $told, MatrixKind $matrix): array
    {
        return [...$this->started($told), ...$matrix === MatrixKind::Full ? [] : [self::STOP]];
    }

    /**
     * @param array<string, string|list<string>> $told
     *
     * @return list<string> Codeception's script, run with the extension enabled and told so
     */
    private function started(array $told): array
    {
        $settings = str_replace(self::KEY_END, self::KEY_END_IN_TEXT, JsonText::compact($told));

        return [
            $this->codecept,
            ...self::RUN,
            self::EXTENSION,
            Extension::class,
            self::OVERRIDE,
            sprintf(self::SETTINGS, Extension::class, $settings),
        ];
    }

    /** @return array<string, string|list<string>> what narrows a coverage run: a group, a filter, or its files */
    private function covering(WholeSuite|Group|Filter|TestPaths $tests): array
    {
        return $tests instanceof TestPaths
            ? [Option::Files->value => array_map(
                static fn(Path $file): string => $file->value(),
                [...$tests->files()],
            )]
            : $this->judgedBy($tests);
    }

    /** @return array<string, string> the settings that keep a run to the tests that judge the unit */
    private function judgedBy(WholeSuite|Group|Filter $judgedBy): array
    {
        return match (true) {
            $judgedBy instanceof Group => [Option::Group->value => $judgedBy->name()],
            $judgedBy instanceof Filter => [Option::Filter->value => $judgedBy->pattern()],
            default => [],
        };
    }
}
