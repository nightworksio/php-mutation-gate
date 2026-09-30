<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function array_keys;
use function explode;
use function getenv;
use function implode;
use function mb_strtolower;

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
     * @param  list<string> $tests   the ids of the tests that cover a mutant, as Pest's coverage map names them
     * @param  int          $longest the most bytes the paths may take, joined by spaces
     * @return list<string> the files, by their paths on disk, in byte order
     */
    public static function of(array $tests, int $longest = Ceiling::BYTES): array
    {
        if (getenv(GateVariable::Narrow->value) !== '1' || $tests === []) {
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

    private static function loaded(): LoadedTests
    {
        if (! array_key_exists(0, self::$loaded)) {
            self::$loaded = [LoadedTests::inThisProcess(self::directory())];
        }

        return self::$loaded[0];
    }
}
