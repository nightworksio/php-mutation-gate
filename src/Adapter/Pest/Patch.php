<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Hunk;
use NightWorksIO\MutationGate\Core\Composer\Package;
use NightWorksIO\MutationGate\Core\Composer\VendorPatch;

use function sprintf;

/**
 * `pest:patch`: the changes to pest-plugin-mutate the gate's `pest.patch`
 * setting relies on.
 * - A mutant's `--filter` too long to start a process with is dropped, so the
 *   mutant runs every test its run loads, which can only kill more mutants.
 * - Given a coverage map another job wrote, the opening run is the canary
 *   group alone, and the map is copied in place of the one that run wrote.
 * - Each mutant is allowed the standard mutant limit of its covering tests'
 *   own time, within the bounds the gate names (see MutantTime), not the
 *   time of the whole suite, and its run is stopped once no test of it has
 *   finished for its silence limit, the standard limit of its slowest test
 *   (see Silence).
 * - Given a list of native ids (see OnlyList), a run makes only those mutants.
 * - Where the gate narrows a run, a mutant's own run loads only the test files
 *   its covering tests need (see CoveringFiles), not every test file, and the
 *   files it loads are recorded by the mutant's mutated copy.
 *
 * Applied as a VendorPatch: every anchor checked before anything is written.
 */
final readonly class Patch
{
    /** The command that applies the patch. */
    public const string COMMAND = 'pest:patch';

    /** The files the patch changes, by their paths under pest-plugin-mutate's source directory. */
    private const string MUTATION_TEST = 'MutationTest.php';

    private const string MUTATE_PLUGIN = 'Plugins/Mutate.php';

    private const string TEST_RUNNER = 'Tester/MutationTestRunner.php';
    private const string FILTER_SHIPS = <<<'PHP'
                $process = new Process(
                    command: [
                        ...$filteredArguments,
                        '--bail',
                        '--filter="'.implode('|', $filters).'"',
                    ],
        PHP;

    private const string FILTER_BECOMES = <<<'PHP'
                {MARK} a filter too long to start a process with is left out.
                $filter = '--filter="'.implode('|', $filters).'"';

                $process = new Process(
                    command: [
                        ...$filteredArguments,
                        '--bail',
                        ...(strlen($filter) < %d ? [$filter] : []),
                    ],
        PHP;

    private const string CANARY_SHIPS = <<<'PHP'
                $mutationTestRunner->setStartTime(microtime(true));

                return $arguments;
        PHP;

    private const string CANARY_BECOMES = <<<'PHP'
                $mutationTestRunner->setStartTime(microtime(true));

                {MARK} with a shared coverage map, the opening run is the canary group.
                if ((string) getenv('%1$s') !== '') {
                    $arguments[] = '--group='.getenv('%2$s');
                }

                return $arguments;
        PHP;

    private const string MAP_SHIPS = <<<'PHP'
                    microtime(true) - $this->startTime
                );
        PHP;

    private const string MAP_BECOMES = <<<'PHP'
                    microtime(true) - $this->startTime
                );

                {MARK} read the shared coverage map, and time mutants by its suite.
                if ((string) getenv('%1$s') !== '') {
                    $seconds = (float) getenv('%2$s');
                    $shared = (string) getenv('%1$s');

                    if ($seconds <= 0 || ! is_readable($shared) || ! copy($shared, Coverage::getPath())) {
                        $printer = Container::getInstance()->get(Printer::class);
                        $printer->reportError('mutation-gate could not read the shared coverage map.');

                        return 1;
                    }

                    $telemetry = Container::getInstance()->get(TelemetryRepository::class);
                    $telemetry->initialTestSuiteDuration($seconds);
                }
        PHP;

    private const string TIMES_SHIPS = <<<'PHP'
                $loadedCoverage = require $reportPath;
        PHP;

    private const string TIMES_BECOMES = <<<'PHP'
                $loadedCoverage = require $reportPath;

                {MARK} each test's seconds, as the map Pest loaded timed them, for each mutant's limit.
                if (class_exists(\%1$s::class) && is_array($loadedCoverage)) {
                    \%1$s::remember($loadedCoverage);
                }
        PHP;

    private const string LIMIT_SHIPS = <<<'PHP'
                    timeout: $this->calculateTimeout(),
        PHP;

    private const string LIMIT_BECOMES = <<<'PHP'
                    {MARK} the standard limit of the covering tests' own time, under the gate's cap.
                    timeout: class_exists(\%1$s::class)
                        ? \%1$s::of($covering, $this->mutation->modifiedSourcePath, $this->calculateTimeout())
                        : $this->calculateTimeout(),
        PHP;

    private const string WATCH_SHIPS = <<<'PHP'
                $process->start();

                $this->process = $process;
        PHP;

    private const string WATCH_BECOMES = <<<'PHP'
                $process->start();

                $this->process = $process;

                {MARK} a run no test finishes in for its silence limit is stopped (see Silence).
                if (class_exists(\%1$s::class)) {
                    \%1$s::watch(
                        $process,
                        $covering,
                        $this->mutation->modifiedSourcePath,
                        $filter,
                        $this->mutation->mutator,
                    );
                }
        PHP;

    private const string SILENCE_SHIPS = <<<'PHP'
                        $this->process->checkTimeout();

                        return false;
        PHP;

    private const string SILENCE_BECOMES = <<<'PHP'
                        $this->process->checkTimeout();

                        {MARK} stopped where no test finished for its silence limit (see Silence).
                        if (class_exists(\%1$s::class)) {
                            \%1$s::check($this->process, microtime(true));
                        }

                        return false;
        PHP;

    private const string ONLY_SHIPS = <<<'PHP'
                        $mutationSuite->repository->add($mutation);
        PHP;

    private const string ONLY_BECOMES = <<<'PHP'
                        {MARK} a run again makes only the mutants it names.
                        if ($only !== [] && ! isset($only[$mutation->id])) {
                            continue;
                        }

                        $mutationSuite->repository->add($mutation);
        PHP;

    private const string LISTED_SHIPS = <<<'PHP'
                foreach ($files as $file) {
                    $linesToMutate = [];
        PHP;

    private const string LISTED_BECOMES = <<<'PHP'
                {MARK} the mutants a run again makes, read once; none for every mutant.
                $only = class_exists(\%1$s::class) ? \%1$s::in((string) getenv('%2$s')) : [];

                foreach ($files as $file) {
                    $linesToMutate = [];
        PHP;

    private const string COVERING_SHIPS = <<<'PHP'
                $filters = [];
                foreach (range($this->mutation->startLine, $this->mutation->endLine) as $lineNumber) {
                    foreach ($coveredLines[$this->mutation->file->getRealPath()][$lineNumber] ?? [] as $test) {
        PHP;

    private const string COVERING_BECOMES = <<<'PHP'
                {MARK} the tests that cover the mutant, gathered as Pest builds its filter.
                $filters = [];
                $covering = [];
                foreach (range($this->mutation->startLine, $this->mutation->endLine) as $lineNumber) {
                    foreach ($coveredLines[$this->mutation->file->getRealPath()][$lineNumber] ?? [] as $test) {
                        $covering[] = $test;
        PHP;

    private const string PATHS_SHIPS = <<<'PHP'
                $envs = [
                    Mutate::ENV_MUTATION_TESTING => $this->mutation->file->getRealPath(),
        PHP;

    private const string PATHS_BECOMES = <<<'PHP'
                {MARK} a mutant's own run loads only the test files its covering tests need.
                if (class_exists(\%1$s::class)) {
                    $originalArguments = [
                        ...$originalArguments,
                        ...\%1$s::of($covering, $this->mutation->modifiedSourcePath),
                    ];
                }

                $envs = [
                    Mutate::ENV_MUTATION_TESTING => $this->mutation->file->getRealPath(),
        PHP;

    /** Patch pest-plugin-mutate in a vendor directory, and say what was done. */
    public static function applyIn(string $vendor): string|CannotJudge
    {
        return PackageSource::applyIn(self::patch(), $vendor);
    }

    /** Whether pest-plugin-mutate in a vendor directory carries every hunk, and no hunk another version wrote. */
    public static function isAppliedIn(string $vendor): bool
    {
        return PackageSource::isAppliedIn(self::patch(), $vendor);
    }

    private static function patch(): VendorPatch
    {
        return VendorPatch::of(self::COMMAND, Package::PestMutate->value, ...self::hunks());
    }

    /** @return list<Hunk> */
    private static function hunks(): array
    {
        return [
            Hunk::in(self::MUTATION_TEST, self::FILTER_SHIPS, sprintf(self::FILTER_BECOMES, Ceiling::BYTES)),
            Hunk::in(self::MUTATION_TEST, self::COVERING_SHIPS, self::COVERING_BECOMES),
            Hunk::in(self::MUTATION_TEST, self::PATHS_SHIPS, sprintf(self::PATHS_BECOMES, CoveringFiles::class)),
            Hunk::in(self::MUTATION_TEST, self::LIMIT_SHIPS, sprintf(self::LIMIT_BECOMES, MutantTime::class)),
            Hunk::in(self::MUTATION_TEST, self::WATCH_SHIPS, sprintf(self::WATCH_BECOMES, Silence::class)),
            Hunk::in(self::MUTATION_TEST, self::SILENCE_SHIPS, sprintf(self::SILENCE_BECOMES, Silence::class)),
            Hunk::in(
                self::MUTATE_PLUGIN,
                self::CANARY_SHIPS,
                sprintf(self::CANARY_BECOMES, GateVariable::SharedCoverage->value, GateVariable::Canary->value),
            ),
            Hunk::in(
                self::TEST_RUNNER,
                self::MAP_SHIPS,
                sprintf(self::MAP_BECOMES, GateVariable::SharedCoverage->value, GateVariable::SuiteSeconds->value),
            ),
            Hunk::in(
                self::TEST_RUNNER,
                self::TIMES_SHIPS,
                sprintf(self::TIMES_BECOMES, MutantTime::class),
            ),
            Hunk::in(
                self::TEST_RUNNER,
                self::LISTED_SHIPS,
                sprintf(self::LISTED_BECOMES, OnlyList::class, GateVariable::Only->value),
            ),
            Hunk::in(self::TEST_RUNNER, self::ONLY_SHIPS, self::ONLY_BECOMES),
        ];
    }
}
