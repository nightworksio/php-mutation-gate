<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Project;

use function array_any;
use function array_values;

use const LIBXML_NOERROR;
use const LIBXML_NONET;
use const LIBXML_NOWARNING;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\SuiteDirectory;

use function simplexml_load_string;

use SimpleXMLElement;

use function sprintf;
use function trim;

/**
 * The test suite the project's PHPUnit config declares: the `<directory>`
 * and `<file>` of every `<testsuite>`, less each `<exclude>`, as PHPUnit
 * itself, and the runners over it, read them, each directory with the suffix
 * that tells its files of test cases. Without a config, or one that names no
 * test directory, the tests are in the conventional directory.
 */
final readonly class PhpUnitSuite
{
    private const string DIRECTORIES = '/phpunit/testsuites/testsuite/directory';

    private const string FILES = '/phpunit/testsuites/testsuite/file';

    private const string EXCLUDED = '/phpunit/testsuites/testsuite/exclude';

    private const string NOT_XML = '%s is not XML, so the test suite it declares cannot be read.';

    /** @param non-empty-list<SuiteDirectory> $directories */
    private function __construct(private array $directories, private Paths $files, private Paths $excluded)
    {
    }

    /** The tests in the conventional directory, and nothing left out. */
    public static function conventional(): self
    {
        return new self([SuiteDirectory::conventional()], Paths::none(), Paths::none());
    }

    /** The suite a PHPUnit config, read from this file, declares. */
    public static function declaredIn(Contents $config, Path $file): self|CannotJudge
    {
        $xml = simplexml_load_string($config->text(), options: LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        if (! $xml instanceof SimpleXMLElement) {
            return CannotJudge::because(sprintf(self::NOT_XML, $file->value()));
        }

        $directories = [];

        foreach (self::nodesIn($xml, self::DIRECTORIES) as $node) {
            $directories[] = SuiteDirectory::of(Path::of(trim((string) $node)), (string) $node->attributes()?->suffix);
        }

        return new self(
            $directories === [] ? [SuiteDirectory::conventional()] : $directories,
            self::pathsIn($xml, self::FILES),
            self::pathsIn($xml, self::EXCLUDED),
        );
    }

    /** Whether a file is one of the suite's: in a test directory or named, and not left out. */
    public function holds(Path $file): bool
    {
        return ($this->files->has($file) || array_any(
            $this->directories,
            static fn(SuiteDirectory $directory): bool => $directory->holds($file),
        )) && ! $this->excludes($file);
    }

    /** Whether a file is one of the suite's files of test cases: named, or with its directory's suffix. */
    public function holdsTestCase(Path $file): bool
    {
        return $this->holds($file) && ($this->files->has($file) || array_any(
            $this->directories,
            static fn(SuiteDirectory $directory): bool => $directory->holdsTestCase($file),
        ));
    }

    /**
     * The directories the tests are in, each with the suffix of its files of test cases.
     *
     * @return non-empty-list<SuiteDirectory>
     */
    public function directories(): array
    {
        return $this->directories;
    }

    /** @return list<SimpleXMLElement> */
    private static function nodesIn(SimpleXMLElement $xml, string $query): array
    {
        $nodes = $xml->xpath($query);

        return $nodes === false || $nodes === null ? [] : array_values($nodes);
    }

    private static function pathsIn(SimpleXMLElement $xml, string $query): Paths
    {
        $paths = [];

        foreach (self::nodesIn($xml, $query) as $node) {
            $paths[] = Path::of(trim((string) $node));
        }

        return Paths::of(...$paths);
    }

    private function excludes(Path $file): bool
    {
        return array_any([...$this->excluded], static fn(Path $excluded): bool => $file->within($excluded));
    }
}
