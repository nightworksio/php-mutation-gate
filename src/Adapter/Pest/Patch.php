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
 * `pest:patch`: the two opt-in changes to pest-plugin-mutate the gate's
 * `pest.patch` setting relies on.
 * - A mutant's `--filter` too long to start a process with is dropped, so the
 *   mutant runs against the whole suite, which can only kill more mutants.
 * - Given a coverage map another job wrote, the opening run is the canary
 *   group alone, the map is copied in place of the one that run wrote, and
 *   Pest times its mutants by the seconds the whole suite took.
 *
 * Every anchor is checked before anything is written, so a moved one changes
 * nothing, and patching again finds the patch in place.
 */
final readonly class Patch
{
    /** The variable naming the coverage map a shard reads. */
    public const string COVERAGE = 'MUTATION_GATE_SHARED_COVERAGE';

    /** The variable holding the seconds the whole suite took, one test after another. */
    public const string SECONDS = 'MUTATION_GATE_SUITE_SECONDS';

    /** The variable naming the canary group a shard's opening run is. */
    public const string CANARY = 'MUTATION_GATE_CANARY';

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

        $hunks = self::hunks();
        $patching = array_filter($hunks, static fn(Hunk $hunk): bool => ! $hunk->isAppliedTo($sources[$hunk->file()]));
        $unwritten = array_filter($patching, static fn(Hunk $hunk): bool => file_put_contents(
            sprintf(self::SOURCE, $vendor, $hunk->file()),
            $hunk->applyTo($sources[$hunk->file()]),
        ) === false);

        return $unwritten === [] ? sprintf(
            'pest:patch patched %d of the %d files it changes in pest-plugin-mutate.',
            count($patching),
            count($hunks),
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

    /** @return list<Hunk> */
    private static function hunks(): array
    {
        return [
            Hunk::in('MutationTest.php', self::FILTER_SHIPS, sprintf(self::FILTER_BECOMES, Selection::CEILING)),
            Hunk::in(
                'Plugins/Mutate.php',
                self::CANARY_SHIPS,
                sprintf(self::CANARY_BECOMES, self::COVERAGE, self::CANARY),
            ),
            Hunk::in(
                'Tester/MutationTestRunner.php',
                self::MAP_SHIPS,
                sprintf(self::MAP_BECOMES, self::COVERAGE, self::SECONDS),
            ),
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

        $source = sprintf('%s', file_get_contents($file));

        return $hunk->isAppliedTo($source) || $hunk->fits($source) ? $source : CannotJudge::because(sprintf(
            'pest:patch patched nothing: the lines it rewrites have moved in %s. Install a supported version.',
            $file,
        ));
    }
}
