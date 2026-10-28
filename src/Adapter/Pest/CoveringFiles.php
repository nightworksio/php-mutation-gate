<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_filter;
use function array_flip;
use function array_key_exists;
use function array_unique;
use function array_values;
use function explode;
use function file_put_contents;
use function getenv;
use function implode;
use function is_string;
use function mb_strtolower;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use Pest\TestSuite;

use function sort;
use function sprintf;

/**
 * The test files a mutant's own run loads where a patched run narrows it,
 * read in Pest's parent process, which has loaded the whole suite: the file
 * that declares each covering test's class, every loaded test file that is
 * not inert (see Registration), and every loaded test file that declares a
 * name those files use, in turn. None, so the run loads every test file as
 * Pest ships it, where the run is not narrowed, a covering test's class is
 * not loaded, the paths would pass the length a filter may have, or they
 * cannot be recorded.
 *
 * Each narrowed run's files are recorded by the mutant's mutated copy, so
 * the gate can run them on the unmutated code (see NarrowedKills).
 *
 * What the suite's files declare and use is read once, the first time a
 * mutant asks, and kept for the rest of the process.
 */
final class CoveringFiles
{
    /** @var list<LoadedTests> the suite's test files as this process loaded them, once read */
    private static array $loaded = [];

    /** The test directory the suite is read from, or none for the one Pest runs. */
    private static string $directory = '';

    /** @var array<string, list<string>> the files the last mutant's run loads, by its mutated copy */
    private static array $narrowed = [];

    /**
     * The files, only once they are recorded, in the results file the gate
     * names, as the files the mutant with this mutated copy loads: a kill of
     * a run narrowed with no record would stand unchecked (see NarrowedKills).
     *
     * @param  list<string> $tests   the ids of the tests that cover a mutant, as Pest's coverage map names them
     * @param  string       $mutated the mutated copy Pest serves in the mutant's own process
     * @param  int          $longest the most bytes the paths may take, joined by spaces
     * @return list<string> the files, by their paths on disk, in byte order
     */
    public static function of(array $tests, string $mutated = '', int $longest = Ceiling::BYTES): array
    {
        $paths = getenv(GateVariable::Narrow->value) === '1' && $tests !== [] ? self::needed($tests, $longest) : [];
        $results = getenv(GateVariable::Results->value);

        $recorded = $paths !== [] && $mutated !== '' && is_string($results) && $results !== ''
            && file_put_contents($results, RecordLine::narrowed($mutated, $paths), FILE_APPEND | LOCK_EX) !== false;

        self::$narrowed = [$mutated => $recorded ? $paths : []];

        return self::$narrowed[$mutated];
    }

    /**
     * Those of these tests the run of the mutant with this mutated copy runs
     * where its filter is left out: each whose class a file it loads
     * declares, or every one where it loads every test file.
     *
     * @param  list<string> $tests the tests, by their ids as Pest's coverage map names them
     * @return list<string>
     */
    public static function loading(string $mutated, array $tests): array
    {
        $paths = array_key_exists($mutated, self::$narrowed) ? self::$narrowed[$mutated] : [];

        if ($paths === []) {
            return $tests;
        }

        $loaded = self::loaded();
        $files = array_flip($paths);

        return array_values(array_filter(
            $tests,
            static fn(string $test): bool => array_key_exists($loaded->fileOf(self::classOf($test)), $files),
        ));
    }

    /**
     * Forgets what was read, so the next mutant reads the suite as it is
     * loaded then: from this test directory, or from Pest's where none is named.
     */
    public static function forget(string $testDirectory = ''): void
    {
        self::$loaded = [];
        self::$narrowed = [];
        self::$directory = $testDirectory;
    }

    /** The test directory to read: the one named, or the one Pest runs. */
    private static function directory(): string
    {
        if (self::$directory !== '') {
            return self::$directory;
        }

        $suite = TestSuite::getInstance();

        return sprintf('%s/%s', $suite->rootPath, $suite->testPath);
    }

    /**
     * @param  non-empty-list<string> $tests
     * @return list<string>
     */
    private static function needed(array $tests, int $longest): array
    {
        $loaded = self::loaded();
        $files = [];

        foreach ($tests as $test) {
            $file = $loaded->fileOf(self::classOf($test));

            if ($file === '') {
                return [];
            }

            $files = [...$files, ...$loaded->needs($file)];
        }

        $paths = array_unique($files);
        sort($paths);

        return Ceiling::admits(implode(' ', $paths), $longest) ? $paths : [];
    }

    /** A test's class, by its name in lower case, as its id names it. */
    private static function classOf(string $test): string
    {
        return mb_strtolower(explode('::', $test)[0]);
    }

    private static function loaded(): LoadedTests
    {
        if (! array_key_exists(0, self::$loaded)) {
            self::$loaded = [LoadedTests::inThisProcess(self::directory())];
        }

        return self::$loaded[0];
    }
}
