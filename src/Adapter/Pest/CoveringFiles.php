<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function array_keys;
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
 * that declares each covering test's class, and every loaded test file that
 * declares a name those files use, in turn. None, so the run loads every test
 * file as Pest ships it, where the run is not narrowed, a covering test's
 * class is not loaded, or the paths would pass the length a filter may have.
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

    /**
     * The files, each recorded, where a results file is named, as the files
     * the mutant with this mutated copy loads.
     *
     * @param  list<string> $tests   the ids of the tests that cover a mutant, as Pest's coverage map names them
     * @param  string       $mutated the mutated copy Pest serves in the mutant's own process
     * @param  int          $longest the most bytes the paths may take, joined by spaces
     * @return list<string> the files, by their paths on disk, in byte order
     */
    public static function of(array $tests, string $mutated = '', int $longest = Ceiling::BYTES): array
    {
        $paths = getenv(GateVariable::Narrow->value) === '1' ? self::needed($tests, $longest) : [];
        $results = getenv(GateVariable::Results->value);

        if ($paths !== [] && $mutated !== '' && is_string($results) && $results !== '') {
            file_put_contents($results, RecordLine::narrowed($mutated, $paths), FILE_APPEND | LOCK_EX);
        }

        return $paths;
    }

    /**
     * Forgets what was read, so the next mutant reads the suite as it is
     * loaded then: from this test directory, or from Pest's where none is named.
     */
    public static function forget(string $testDirectory = ''): void
    {
        self::$loaded = [];
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
     * @param  list<string> $tests
     * @return list<string>
     */
    private static function needed(array $tests, int $longest): array
    {
        if ($tests === []) {
            return [];
        }

        $loaded = self::loaded();
        $files = [];

        foreach ($tests as $test) {
            $file = $loaded->fileOf(mb_strtolower(explode('::', $test)[0]));

            if ($file === '') {
                return [];
            }

            foreach ($loaded->needs($file) as $needed) {
                $files[$needed] = true;
            }
        }

        $paths = array_keys($files);
        sort($paths);

        return Ceiling::admits(implode(' ', $paths), $longest) ? $paths : [];
    }

    private static function loaded(): LoadedTests
    {
        if (! array_key_exists(0, self::$loaded)) {
            self::$loaded = [LoadedTests::inThisProcess(self::directory())];
        }

        return self::$loaded[0];
    }
}
