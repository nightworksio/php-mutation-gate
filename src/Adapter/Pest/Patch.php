<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_all;
use function array_filter;
use function array_first;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function basename;
use function count;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function is_file;
use function is_string;
use function is_writable;
use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\CannotJudge;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function sort;
use function sprintf;
use function str_contains;
use function str_replace;

/**
 * `pest:patch`: the changes to pest-plugin-mutate the gate's `pest.patch`
 * setting relies on.
 * - A mutant's `--filter` too long to start a process with is dropped, so the
 *   mutant runs every test its run loads, which can only kill more mutants.
 * - Given a coverage map another job wrote, the opening run is the canary
 *   group alone, the map is copied in place of the one that run wrote, and
 *   Pest times its mutants by the seconds the whole suite took.
 * - Given a list of native ids (see OnlyList), a run makes only those mutants.
 * - Where the gate narrows a run, a mutant's own run loads only the test files
 *   its covering tests need (see CoveringFiles), not every test file.
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
                    $originalArguments = [...$originalArguments, ...\%1$s::of($covering)];
                }

                $envs = [
                    Mutate::ENV_MUTATION_TESTING => $this->mutation->file->getRealPath(),
        PHP;

    /**
     * What begins the comment every hunk writes, and so finds every hunk
     * another version of the gate wrote; each hunk's text holds it as MARKED.
     */
    private const string MARK = '// mutation-gate pest:patch:';

    /** Where a hunk's text holds the mark. */
    private const string MARKED = '{MARK}';

    /** Why pest:patch cannot change a file another version of the gate patched. */
    private const string OTHER_VERSION = "pest:patch patched nothing: %s holds another gate's patch. Run %s.";

    /** What takes another gate's patch out: installing the plugin afresh, which runs pest:patch again. */
    private const string REINSTALL = 'composer reinstall pestphp/pest-plugin-mutate';

    /** Why pest:patch cannot change a file whose lines have moved. */
    private const string MOVED
        = 'pest:patch patched nothing: the lines it rewrites have moved in %s. Install a supported version.';

    /** Why pest:patch cannot read a file it changes. */
    private const string UNREADABLE = 'pest:patch cannot read %s. Is pest-plugin-mutate installed?';

    /** Why pest:patch cannot change a file it has to. */
    private const string UNWRITABLE = 'pest:patch cannot write %s/%s. Make the vendor directory writable.';

    /** Patch pest-plugin-mutate in a vendor directory, and say what was done. */
    public static function applyIn(string $vendor): string|CannotJudge
    {
        $sources = self::sources($vendor);
        $other = $sources instanceof CannotJudge ? [] : self::otherVersions($vendor, $sources);
        $patching = match (true) {
            $sources instanceof CannotJudge => $sources,
            $other !== [] => CannotJudge::because(sprintf(self::OTHER_VERSION, $other[0], self::REINSTALL)),
            default => self::patching($vendor, $sources),
        };

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
            count(self::hunkFiles()),
        ) : CannotJudge::because(sprintf(self::UNWRITABLE, $vendor, 'pestphp/pest-plugin-mutate/src'));
    }

    /** Whether pest-plugin-mutate in a vendor directory carries every hunk, and no hunk another version wrote. */
    public static function isAppliedIn(string $vendor): bool
    {
        $sources = self::sources($vendor);

        return ! $sources instanceof CannotJudge
            && self::otherVersions($vendor, $sources) === []
            && array_all(self::hunks(), static fn(Hunk $hunk): bool => $hunk->isAppliedTo($sources[$hunk->file()]));
    }

    /**
     * Each file a hunk changes, by its path under the source directory; or
     * why one cannot be read.
     *
     * @return array<string, string>|CannotJudge
     */
    private static function sources(string $vendor): array|CannotJudge
    {
        $sources = [];

        foreach (self::hunkFiles() as $relative) {
            $file = sprintf(self::SOURCE, $vendor, $relative);

            if (! is_file($file)) {
                return CannotJudge::because(sprintf(self::UNREADABLE, $file));
            }

            $sources[$relative] = sprintf('%s', file_get_contents($file));
        }

        return $sources;
    }

    /** @return list<string> each file a hunk changes, by its path under the source directory, once */
    private static function hunkFiles(): array
    {
        return array_values(array_unique(array_map(static fn(Hunk $hunk): string => $hunk->file(), self::hunks())));
    }

    /**
     * Every PHP file of the plugin's source that carries a line another
     * version of the gate's pest:patch wrote which no hunk of this one
     * writes: a hunk written differently, or in a file this version leaves
     * alone, which patching again cannot take out.
     *
     * @param  array<string, string> $sources each file a hunk changes, by its path under the source directory
     * @return list<string>
     */
    private static function otherVersions(string $vendor, array $sources): array
    {
        $left = $sources;

        foreach (self::hunks() as $hunk) {
            $left[$hunk->file()] = $hunk->takenFrom($left[$hunk->file()]);
        }

        $marked = [];
        $root = sprintf(self::SOURCE, $vendor, '');
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($entries as $pathname => $entry) {
            $relative = is_string($pathname) ? mb_substr($pathname, mb_strlen($root)) : '';
            $marked = is_string($pathname) && self::carriesMark($pathname, $relative, $left)
                ? [...$marked, $pathname]
                : $marked;
        }

        sort($marked);

        return $marked;
    }

    /**
     * Whether a file of the plugin's source carries the mark: as it is on
     * disk, or with this version's hunks taken out where it is one they change.
     *
     * @param array<string, string> $left each file a hunk changes, less the hunks, by its path under the
     *                                    source directory
     */
    private static function carriesMark(string $pathname, string $relative, array $left): bool
    {
        $source = array_key_exists($relative, $left) ? $left[$relative] : (string) file_get_contents($pathname);

        return str_contains($source, self::MARK);
    }

    /**
     * Each file's source with every hunk it lacks applied, of the files a
     * hunk changes, each hunk checked against the source as the hunks before
     * it left it; or nothing, where a line a hunk rewrites has moved or a
     * file it changes cannot be written.
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

        $changed = array_filter(
            $patched,
            static fn(string $source, string $file): bool => $source !== $sources[$file],
            ARRAY_FILTER_USE_BOTH,
        );
        $locked = array_filter(
            array_keys($changed),
            static fn(string $file): bool => ! is_writable(sprintf(self::SOURCE, $vendor, $file)),
        );
        $first = sprintf(self::SOURCE, $vendor, $locked === [] ? '' : array_first($locked));

        return $locked === []
            ? $changed
            : CannotJudge::because(sprintf(self::UNWRITABLE, dirname($first), basename($first)));
    }

    /** @return list<Hunk> */
    private static function hunks(): array
    {
        return [
            self::hunk('MutationTest.php', self::FILTER_SHIPS, sprintf(self::FILTER_BECOMES, self::CEILING)),
            self::hunk('MutationTest.php', self::COVERING_SHIPS, self::COVERING_BECOMES),
            self::hunk('MutationTest.php', self::PATHS_SHIPS, sprintf(self::PATHS_BECOMES, CoveringFiles::class)),
            self::hunk(
                'Plugins/Mutate.php',
                self::CANARY_SHIPS,
                sprintf(self::CANARY_BECOMES, GateVariable::SharedCoverage->value, GateVariable::Canary->value),
            ),
            self::hunk(
                'Tester/MutationTestRunner.php',
                self::MAP_SHIPS,
                sprintf(self::MAP_BECOMES, GateVariable::SharedCoverage->value, GateVariable::SuiteSeconds->value),
            ),
            self::hunk(
                'Tester/MutationTestRunner.php',
                self::LISTED_SHIPS,
                sprintf(self::LISTED_BECOMES, OnlyList::class, GateVariable::Only->value),
            ),
            self::hunk('Tester/MutationTestRunner.php', self::ONLY_SHIPS, self::ONLY_BECOMES),
        ];
    }

    /** A hunk, its text holding the mark where it says MARKED. */
    private static function hunk(string $file, string $ships, string $becomes): Hunk
    {
        return Hunk::in($file, $ships, str_replace(self::MARKED, self::MARK, $becomes));
    }

}
