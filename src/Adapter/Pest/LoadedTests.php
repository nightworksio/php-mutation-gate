<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_filter;
use function array_key_exists;
use function array_map;
use function file_get_contents;
use function get_declared_classes;
use function get_included_files;
use function is_dir;
use function is_file;
use function is_string;
use function is_subclass_of;
use function mb_strlen;
use function mb_strtolower;
use function mb_substr;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Naming;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Php\NamedFiles;
use NightWorksIO\MutationGate\Core\Php\PhpFile;
use Pest\Support\DatasetInfo;
use PHPUnit\Framework\TestCase;

use function realpath;
use function sprintf;
use function str_starts_with;

/**
 * The files a process has loaded from its test directory, among them the file
 * that declares each test case class, and what each needs: every loaded file
 * that is not inert (see Registration), less those Pest loads in every
 * process, and the loaded files of the test directory that declare a class,
 * trait, function or constant one of those uses, fully qualified or spelt in
 * a string, and what those need in turn. A name none of them declares comes
 * from what every run loads, such as the autoloader.
 */
final class LoadedTests
{
    /** What Pest loads from the test directory in every process, before any test file, as its BootFiles names them. */
    private const array BOOTED = ['Expectations', 'Expectations.php', 'Helpers', 'Helpers.php', 'Pest.php'];

    /** @var array<string, list<string>> what each file needs, by its path, once asked */
    private array $needs = [];

    /**
     * @param array<string, string> $files  each test case class's file, by the class's name in lower case
     * @param NamedFiles            $naming the loaded test files each file names
     * @param list<string>          $acting the loaded test files that are not inert (see Registration)
     */
    private function __construct(
        private readonly array $files,
        private readonly NamedFiles $naming,
        private readonly array $acting,
    ) {
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

        $reads = [];

        foreach (array_filter(array_map(self::onDisk(...), get_included_files()), $inside) as $file) {
            $reads[$file] = PhpFile::read(Contents::of(sprintf('%s', file_get_contents($file))));
        }

        return new self($classes, NamedFiles::read($reads), self::acting($reads, $under));
    }

    /** The file that declares a test case class, by its name in lower case; none for a class not loaded. */
    public function fileOf(string $class): string
    {
        return array_key_exists($class, $this->files) ? $this->files[$class] : '';
    }

    /**
     * The file itself, every loaded test file that is not inert, and every
     * loaded test file those need, transitively.
     *
     * @return list<string>
     */
    public function needs(string $file): array
    {
        if (! array_key_exists($file, $this->needs)) {
            $this->needs[$file] = $this->naming->reachedFrom($file, ...$this->acting);
        }

        return $this->needs[$file];
    }

    /**
     * The files whose loading acts on what other files find, less those Pest
     * loads in every process anyway.
     *
     * @param  array<string, PhpFile> $reads each loaded test file, read, by its path
     * @param  string                 $under the test directory, with a trailing slash
     * @return list<string>
     */
    private static function acting(array $reads, string $under): array
    {
        $acting = [];

        foreach ($reads as $file => $read) {
            $booted = self::booted($file, mb_substr($file, mb_strlen($under)));
            $acting = $booted || Registration::inert($read) ? $acting : [...$acting, $file];
        }

        return $acting;
    }

    /** Whether Pest loads a file of the test directory, by its path and its path within, in every process. */
    private static function booted(string $file, string $within): bool
    {
        foreach (self::BOOTED as $booted) {
            if ($within === $booted || str_starts_with($within, sprintf('%s/', $booted))) {
                return true;
            }
        }

        return DatasetInfo::isADatasetsFile($file) || DatasetInfo::isInsideADatasetsDirectory($file);
    }

    /** The file by its real path, where it is one on disk. */
    private static function onDisk(string $file): string
    {
        $real = is_file($file) ? realpath($file) : false;

        return is_string($real) ? $real : '';
    }
}
