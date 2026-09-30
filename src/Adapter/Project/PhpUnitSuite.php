<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Project;

use function array_values;
use function count;

use const LIBXML_NOERROR;
use const LIBXML_NONET;
use const LIBXML_NOWARNING;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\TestsDirectory;

use function simplexml_load_string;

use SimpleXMLElement;

use function sprintf;
use function str_starts_with;
use function trim;

/**
 * The test suite the project's PHPUnit config declares: the `<directory>`
 * and `<file>` of every `<testsuite>`, less each `<exclude>`, as PHPUnit
 * itself, and the runners over it, read them. Without a config, or one that
 * names no test directory, the tests are in the conventional directory.
 */
final readonly class PhpUnitSuite
{
    private const string DIRECTORIES = '/phpunit/testsuites/testsuite/directory';

    private const string FILES = '/phpunit/testsuites/testsuite/file';

    private const string EXCLUDED = '/phpunit/testsuites/testsuite/exclude';

    private const string NOT_XML = '%s is not XML, so the test suite it declares cannot be read.';

    private function __construct(private Paths $directories, private Paths $files, private Paths $excluded)
    {
    }

    /** The tests in the conventional directory, and nothing left out. */
    public static function conventional(): self
    {
        return new self(Paths::of(TestsDirectory::conventional()), Paths::none(), Paths::none());
    }

    /** Whether a file is one of the suite's: in a test directory or named, and not left out. */
    public function holds(Path $file): bool
    {
        return ($this->isInAny($file, $this->directories) || $this->files->has($file))
            && ! $this->isInAny($file, $this->excluded);
    }

    /** The directories the tests are in. */
    public function directories(): Paths
    {
        return $this->directories;
    }

    /** The suite a PHPUnit config, read from this file, declares. */
    public static function declaredIn(Contents $config, Path $file): self|CannotJudge
    {
        $xml = simplexml_load_string($config->text(), options: LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        if (! $xml instanceof SimpleXMLElement) {
            return CannotJudge::because(sprintf(self::NOT_XML, $file->value()));
        }

        $directories = self::pathsIn($xml, self::DIRECTORIES);

        return new self(
            count($directories) > 0 ? $directories : Paths::of(TestsDirectory::conventional()),
            self::pathsIn($xml, self::FILES),
            self::pathsIn($xml, self::EXCLUDED),
        );
    }

    private static function pathsIn(SimpleXMLElement $xml, string $query): Paths
    {
        $nodes = $xml->xpath($query);
        $paths = [];

        foreach ($nodes === false || $nodes === null ? [] : array_values($nodes) as $node) {
            $paths[] = Path::of(trim((string) $node));
        }

        return Paths::of(...$paths);
    }

    private function isInAny(Path $file, Paths $directories): bool
    {
        foreach ($directories as $directory) {
            if ($directory->value() === '.' || str_starts_with($file->value(), sprintf('%s/', $directory->value()))) {
                return true;
            }
        }

        return false;
    }
}
