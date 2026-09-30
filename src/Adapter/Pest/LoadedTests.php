<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_diff;
use function array_filter;
use function array_flip;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function file_get_contents;
use function get_declared_classes;
use function get_included_files;
use function is_dir;
use function is_file;
use function is_string;
use function is_subclass_of;
use function mb_strtolower;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Naming;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Php\PhpFile;
use PHPUnit\Framework\TestCase;

use function realpath;
use function sprintf;
use function str_starts_with;

/**
 * The files a process has loaded from its test directory, among them the file
 * that declares each test case class, and what each needs: the loaded files
 * of the test directory that declare a class, trait, function or other name
 * it uses, fully qualified or spelt in a string, and what those need in turn.
 * A name none of them declares comes from what every run loads, such as the
 * autoloader.
 */
final class LoadedTests
{
    /** @var array<string, list<string>> what each file needs, by its path, once asked */
    private array $needs = [];

    /**
     * @param array<string, string>       $files  each test case class's file, by the class's name in lower case
     * @param array<string, list<string>> $naming the loaded test files each file names, by its path
     */
    private function __construct(private readonly array $files, private readonly array $naming)
    {
    }

    /**
     * The files under the test directory this process has loaded, read from
     * their source, and the test case classes they declare.
     */
    public static function inThisProcess(string $testDirectory): self
    {
        $root = is_dir($testDirectory) ? realpath($testDirectory) : false;
        $under = is_string($root) ? sprintf('%s/', $root) : '';
        $inside = static fn(string $file): bool => $under !== '' && str_starts_with($file, $under);
        $classes = [];

        foreach (get_declared_classes() as $class) {
            $file = is_subclass_of($class, TestCase::class) ? self::onDisk(Naming::fileOfClass($class)) : '';
            $classes = $inside($file) ? [...$classes, mb_strtolower($class) => $file] : $classes;
        }

        $loaded = array_values(array_filter(array_map(self::onDisk(...), get_included_files()), $inside));

        return new self($classes, self::naming($loaded));
    }

    /** The file that declares a test case class, by its name in lower case; none for a class not loaded. */
    public function fileOf(string $class): string
    {
        return array_key_exists($class, $this->files) ? $this->files[$class] : '';
    }

    /**
     * The file itself, and every loaded test file it needs, transitively.
     *
     * @return list<string>
     */
    public function needs(string $file): array
    {
        if (! array_key_exists($file, $this->needs)) {
            $this->needs[$file] = $this->reachedFrom($file);
        }

        return $this->needs[$file];
    }

    /** @return list<string> the file, and every loaded test file it names, transitively, in the order reached */
    private function reachedFrom(string $file): array
    {
        $reached = [$file => 0];
        $queue = [$file];

        $at = 0;

        while ($at < count($queue)) {
            $named = array_key_exists($queue[$at], $this->naming) ? $this->naming[$queue[$at]] : [];
            $new = array_values(array_diff(array_unique($named), array_keys($reached)));
            $reached += array_flip($new);
            $queue = [...$queue, ...$new];
            $at++;
        }

        return $queue;
    }

    /**
     * The loaded test files each of these names, by what each declares.
     *
     * @param  list<string>                $files
     * @return array<string, list<string>> by each file's path
     */
    private static function naming(array $files): array
    {
        $reads = [];
        $declaring = [];

        foreach ($files as $file) {
            $reads[$file] = PhpFile::read(Contents::of(sprintf('%s', file_get_contents($file))));

            foreach ($reads[$file]->declares()->all() as $name) {
                $declaring[$name][] = $file;
            }
        }

        $naming = [];

        foreach ($reads as $file => $read) {
            $names = [...$read->mentioned()->all(), ...$read->quoted()->all()];
            $naming[$file] = array_merge(...array_map(
                static fn(string $name): array => array_key_exists($name, $declaring) ? $declaring[$name] : [],
                $names,
            ));
        }

        return $naming;
    }

    /** The file by its real path, where it is one on disk. */
    private static function onDisk(string $file): string
    {
        $real = is_file($file) ? realpath($file) : false;

        return is_string($real) ? $real : '';
    }
}
