<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_all;
use function array_filter;
use function basename;
use function count;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function is_file;
use function is_writable;

use NightWorksIO\MutationGate\Core\CannotJudge;

use function sprintf;

/**
 * `pest:patch`: the changes to pest-plugin-mutate the gate's `pest.patch`
 * setting relies on.
 * - A mutant's `--filter` too long to start a process with is dropped, so the
 *   mutant runs against the whole suite, which can only kill more mutants.
 * - Given a coverage map another job wrote, the opening run is the canary
 *   group alone, the map is copied in place of the one that run wrote, and
 *   Pest times its mutants by the seconds the whole suite took.
 * - Given a list of native ids (see OnlyList), a run makes only those mutants.
 *
 * Every anchor is checked, against the source as the hunks before it left
 * it, before anything is written, so a moved one changes nothing, and
 * patching again finds the patch in place.
 */
final readonly class Patch
{
    /** The longest `--filter` argument, in bytes, the patch starts a mutant's process with. */
    public const int CEILING = 100000;

    private const string SOURCE = '%s/pestphp/pest-plugin-mutate/src/%s';

    private const string FILTER_SHIPS = <<<'PHP'
                $process = new Process(
                    command: [
                        ...$filteredArguments,
                        '--bail',
                        '--filter="'.implode('|', $filters).'"',
                    ],
        PHP;

    private const string FILTER_BECOMES = <<<'PHP'
                // mutation-gate pest:patch: a filter too long to start a process with is left out.
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

                // mutation-gate pest:patch: with a shared coverage map, the opening run is the canary group.
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

                // mutation-gate pest:patch: read the shared coverage map, and time mutants by its suite.
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

    private const string ONLY_SHIPS = <<<'PHP'
                        $mutationSuite->repository->add($mutation);
        PHP;

    private const string ONLY_BECOMES = <<<'PHP'
                        // mutation-gate pest:patch: a run again makes only the mutants it names.
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
                // mutation-gate pest:patch: the mutants a run again makes, read once; none for every mutant.
                $only = class_exists(\%1$s::class) ? \%1$s::in((string) getenv('%2$s')) : [];

                foreach ($files as $file) {
                    $linesToMutate = [];
        PHP;

    /** Why pest:patch cannot change a file whose lines have moved. */
    private const string MOVED
        = 'pest:patch patched nothing: the lines it rewrites have moved in %s. Install a supported version.';

    /** Why pest:patch cannot change a file it has to. */
    private const string UNWRITABLE = 'pest:patch cannot write %s/%s. Make the vendor directory writable.';

    /** Patch pest-plugin-mutate in a vendor directory, and say what was done. */
    public static function applyIn(string $vendor): string|CannotJudge
    {
        $sources = [];

        foreach (self::hunks() as $hunk) {
            $source = self::read($vendor, $hunk);

            if ($source instanceof CannotJudge) {
                return $source;
            }

            $sources[$hunk->file()] = $source;
        }

        $patching = self::patching($vendor, $sources);

        if ($patching instanceof CannotJudge) {
            return $patching;
        }

        $unwritten = 0;

        foreach ($patching as $file => $source) {
            $unwritten += file_put_contents(sprintf(self::SOURCE, $vendor, $file), $source) === false ? 1 : 0;
        }

        return $unwritten === 0 ? sprintf(
            'pest:patch patched %d of the %d files it changes in pest-plugin-mutate.',
            count($patching),
            count($sources),
        ) : CannotJudge::because(sprintf(self::UNWRITABLE, $vendor, 'pestphp/pest-plugin-mutate/src'));
    }

    /** Whether pest-plugin-mutate in a vendor directory carries every hunk. */
    public static function isAppliedIn(string $vendor): bool
    {
        return array_all(self::hunks(), static function (Hunk $hunk) use ($vendor): bool {
            $file = sprintf(self::SOURCE, $vendor, $hunk->file());

            return is_file($file) && $hunk->isAppliedTo(sprintf('%s', file_get_contents($file)));
        });
    }

    /**
     * Each file's source with every hunk it lacks applied, of the files a
     * hunk changes, each hunk checked against the source as the hunks before
     * it left it; or nothing, where a line a hunk rewrites has moved.
     *
     * @param  array<string, string> $sources each file's source, by its path under the source directory
     * @return array<string, string>|CannotJudge
     */
    private static function patching(string $vendor, array $sources): array|CannotJudge
    {
        $patched = $sources;

        foreach (self::hunks() as $hunk) {
            $source = $patched[$hunk->file()];

            if (! $hunk->isAppliedTo($source) && ! $hunk->fits($source)) {
                return CannotJudge::because(sprintf(self::MOVED, sprintf(self::SOURCE, $vendor, $hunk->file())));
            }

            $patched[$hunk->file()] = $hunk->isAppliedTo($source) ? $source : $hunk->applyTo($source);
        }

        return array_filter(
            $patched,
            static fn(string $source, string $file): bool => $source !== $sources[$file],
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** @return list<Hunk> */
    private static function hunks(): array
    {
        return [
            Hunk::in('MutationTest.php', self::FILTER_SHIPS, sprintf(self::FILTER_BECOMES, self::CEILING)),
            Hunk::in(
                'Plugins/Mutate.php',
                self::CANARY_SHIPS,
                sprintf(self::CANARY_BECOMES, GateVariable::SharedCoverage->value, GateVariable::Canary->value),
            ),
            Hunk::in(
                'Tester/MutationTestRunner.php',
                self::MAP_SHIPS,
                sprintf(self::MAP_BECOMES, GateVariable::SharedCoverage->value, GateVariable::SuiteSeconds->value),
            ),
            Hunk::in(
                'Tester/MutationTestRunner.php',
                self::LISTED_SHIPS,
                sprintf(self::LISTED_BECOMES, OnlyList::class, GateVariable::Only->value),
            ),
            Hunk::in('Tester/MutationTestRunner.php', self::ONLY_SHIPS, self::ONLY_BECOMES),
        ];
    }

    /** A file's source, where it can be patched or already is. */
    private static function read(string $vendor, Hunk $hunk): string|CannotJudge
    {
        $file = sprintf(self::SOURCE, $vendor, $hunk->file());

        if (! is_file($file)) {
            return CannotJudge::because(sprintf('pest:patch cannot read %s. Is pest-plugin-mutate installed?', $file));
        }

        if (! is_writable($file)) {
            return CannotJudge::because(sprintf(self::UNWRITABLE, dirname($file), basename($file)));
        }

        return sprintf('%s', file_get_contents($file));
    }
}
