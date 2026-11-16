<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_filter;
use function array_key_exists;
use function array_unique;
use function array_values;
use function file_get_contents;
use function get_declared_classes;
use function get_included_files;
use function getenv;
use function is_file;
use function is_string;
use function is_subclass_of;
use function mb_strlen;
use function mb_strtolower;
use function mb_substr;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Naming;
use NightWorksIO\MutationGate\Core\Php\NamedFiles;
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
 *
 * @internal
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
     * their source, each read again only where its text changed since a
     * process of the run read it (see SuiteReadings), and the test case
     * classes they declare.
     */
    public static function inThisProcess(string $testDirectory): self
    {
        $root = realpath($testDirectory);

        if (! is_string($root)) {
            return new self([], NamedFiles::read([]), []);
        }

        $under = sprintf('%s/', $root);
        $inside = static fn(string $file): bool => str_starts_with($file, $under);
        $classes = [];

        $tests = array_filter(
            get_declared_classes(),
            static fn(string $class): bool => is_subclass_of($class, TestCase::class),
        );

        foreach ($tests as $class) {
            foreach (array_filter(self::onDisk(Naming::fileOfClass($class)), $inside) as $file) {
                $classes[mb_strtolower($class)] = $file;
            }
        }

        $texts = [];

        foreach (array_filter(get_included_files(), $inside) as $file) {
            $texts[$file] = sprintf('%s', file_get_contents($file));
        }

        $readings = SuiteReadings::beside(getenv(GateVariable::Results->value))->of($texts);
        $declares = [];
        $mentions = [];

        foreach ($readings as $file => $reading) {
            $declares[$file] = $reading->declares;
            $mentions[$file] = $reading->mentions;
        }

        return new self($classes, NamedFiles::byName($declares, $mentions), self::acting($readings, $under));
    }

    /** @return list<string> every file that declares a loaded test case class, each once */
    public function caseFiles(): array
    {
        return array_values(array_unique(array_values($this->files)));
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
     * @param  array<string, FileReading> $readings each loaded test file's reading, by its path
     * @param  string                     $under    the test directory, with a trailing slash
     * @return list<string>
     */
    private static function acting(array $readings, string $under): array
    {
        $acting = [];

        foreach ($readings as $file => $reading) {
            if (! $reading->inert && ! self::booted($file, mb_substr($file, mb_strlen($under)))) {
                $acting[] = $file;
            }
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

    /**
     * The file by its real path, where it is one on disk; none where it is not.
     *
     * @return list<string>
     */
    private static function onDisk(string $file): array
    {
        $real = realpath($file);

        return is_string($real) && is_file($real) ? [$real] : [];
    }
}
