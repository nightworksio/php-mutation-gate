<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_any;
use function array_flip;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_push;
use function array_unique;
use function array_values;
use function basename;
use function dirname;
use function explode;
use function file_get_contents;
use function implode;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function mb_strtoupper;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\TestClassFiles;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestMethod;

use function preg_replace;
use function preg_replace_callback;
use function scandir;
use function sort;
use function sprintf;
use function str_ends_with;
use function str_replace;

/**
 * The test files of a project, which of them hold a class a filter's
 * `<Class>::` selects, and which hold a test. Pest names a test file's class
 * after its path, keeping only its letters and digits, and PHPUnit names it
 * after its file, so a file is selected when that name ends in the class the
 * filter names; a file holds a test where Pest declares its class for the
 * file, or the file declares it itself.
 *
 * It lists the files, and names each one's class, once, and answers each set
 * of classes once: many units share a set, and a project holds thousands of
 * test files.
 */
final class TestFiles
{
    /** What Pest removes from a file's name to make its class name. */
    private const array NOT_IN_A_CLASS_NAME = ['/%[a-fA-F0-9]{2}/', '/[^\p{L}\p{N}]/u'];

    /** The first letter of a path where `ucfirst` raises it: an ASCII lower-case one. */
    private const string LOWER_FIRST = '/^[a-z]/';

    /** What Pest removes from a file's path to make its class's full name. */
    private const array NOT_IN_A_CLASS_PATH = ['/%[a-fA-F0-9]{2}/', '/\\\\[\'"]/', '/[^\p{L}\p{N}\\\\]/u'];

    /** @var list<array<string, string>> each test file's class name, by its path, once listed */
    private array $listed = [];

    /** @var array<string, Paths> the files each set of classes names, by the set */
    private array $named = [];

    /** @var array<string, Paths> the files that hold a test of each set of classes, by the set */
    private array $held = [];

    public function __construct(private readonly Project $project)
    {
    }

    /** Every PHP file under the project's test directories, each directory's entries in name order. */
    public function all(): Paths
    {
        return Paths::of(...array_map(Path::of(...), array_keys($this->classes())));
    }

    /**
     * The files whose class name ends in one of these class names.
     *
     * @param list<string> $classes
     */
    public function naming(array $classes): Paths
    {
        $asked = array_unique($classes);
        sort($asked);
        $key = implode("\n", $asked);

        if (! array_key_exists($key, $this->named)) {
            $named = [];

            foreach ($this->classes() as $file => $class) {
                if (array_any($asked, static fn(string $selected): bool => str_ends_with($class, $selected))) {
                    $named[] = Path::of($file);
                }
            }

            $this->named[$key] = Paths::of(...$named);
        }

        return $this->named[$key];
    }

    /**
     * Those of these tests that these test files hold: the tests of the class
     * Pest declares for each file, named after its path, and of each class a
     * file declares itself, or is named after where the file is gone.
     */
    public function holding(Paths $files, TestIds $tests): TestIds
    {
        $placed = TestClassFiles::held($tests, $files, $this->contentsOf(...));
        $pest = [];

        foreach ($files as $file) {
            $pest[$this->pestClassOf($file)] = true;
        }

        foreach ($tests as $test) {
            $placed = array_key_exists(TestMethod::classOf($test), $pest) ? $placed->with($test) : $placed;
        }

        return $placed;
    }

    /**
     * The test files that hold one of these tests: the file Pest declares a
     * test's class for, or the file named after the class that declares it
     * itself. A file under the test directories that holds none of them, such
     * as a helper whose name ends in a test's class name, is not one.
     */
    public function holdingAny(TestIds $tests): Paths
    {
        $classes = array_values(array_unique(array_map(TestMethod::classOf(...), [...$tests])));
        sort($classes);
        $key = implode("\n", $classes);

        if (! array_key_exists($key, $this->held)) {
            $this->held[$key] = $this->holdingAClassOf($classes);
        }

        return $this->held[$key];
    }

    /**
     * The test files that hold a test of one of these classes: those Pest
     * declares one for, in the order they are listed, then those that declare
     * one themselves.
     *
     * @param list<string> $classes fully qualified
     */
    private function holdingAClassOf(array $classes): Paths
    {
        $wanted = array_flip($classes);
        $declaring = TestClassFiles::wanting($classes);
        $pest = [];

        foreach ($this->all() as $file) {
            $code = $declaring->mayDeclare($file) ? $this->contentsOf($file) : Missing::at($file);
            $declaring = $code instanceof Contents ? $declaring->readIn($file, $code) : $declaring;
            $pest = array_key_exists($this->pestClassOf($file), $wanted) ? [...$pest, $file] : $pest;
        }

        return Paths::of(...$pest, ...$declaring->files());
    }

    /** What a test file holds, or that it is gone. */
    private function contentsOf(Path $file): Contents|Missing
    {
        $disk = $this->project->absolute($file);

        return is_file($disk) ? Contents::of(sprintf('%s', file_get_contents($disk))) : Missing::at($file);
    }

    /**
     * The class Pest declares for a test file, from its path from the
     * project's root, as Pest's test case factory spells it: the directory
     * with its first letter raised and the name up to its first dot, with
     * only letters, digits and namespace separators kept, under `P`.
     */
    private function pestClassOf(Path $file): string
    {
        $name = explode('.', basename($file->value()))[0];
        $directory = dirname($this->raised($file->value()));
        $spelt = str_replace('/', '\\', sprintf('%s/%s', $directory, $name));

        return sprintf('P\\%s', preg_replace(self::NOT_IN_A_CLASS_PATH, '', $spelt) ?? $spelt);
    }

    /** A path with its first letter raised where it is an ASCII one, as PHP's `ucfirst` raises it. */
    private function raised(string $path): string
    {
        return preg_replace_callback(
            self::LOWER_FIRST,
            static fn(array $first): string => mb_strtoupper($first[0]),
            $path,
        ) ?? $path;
    }

    /** @return array<string, string> each test file's class name, by its path as the project spells it */
    private function classes(): array
    {
        if ($this->listed === []) {
            $classes = [];

            foreach ($this->project->tests() as $directory) {
                foreach (self::under($this->project->absolute($directory)) as $found) {
                    $path = $this->project->relative($found);
                    $name = $path->stem();
                    $classes[$path->value()] = preg_replace(self::NOT_IN_A_CLASS_NAME, '', $name) ?? $name;
                }
            }

            $this->listed = [$classes];
        }

        return $this->listed[0];
    }

    /** @return list<string> the PHP files an entry of a directory is, or holds, by their paths on disk */
    private static function entry(string $directory, string $entry): array
    {
        $path = sprintf('%s/%s', $directory, $entry);

        return match (true) {
            $entry === '.' || $entry === '..' || (is_link($path) && is_dir($path)) => [],
            is_dir($path) => self::under($path),
            Path::of($entry)->isPhp() => [$path],
            default => [],
        };
    }

    /** @return list<string> every PHP file under a directory, by its path on disk */
    private static function under(string $directory): array
    {
        $entries = is_dir($directory) ? scandir($directory) : [];
        $found = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            array_push($found, ...self::entry($directory, $entry));
        }

        return $found;
    }
}
