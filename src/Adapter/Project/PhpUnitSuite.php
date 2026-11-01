<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Project;

use function array_values;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Xml;
use NightWorksIO\MutationGate\Core\Test\DeclaredSuite;
use NightWorksIO\MutationGate\Core\Test\DeclaredSuites;
use NightWorksIO\MutationGate\Core\Test\SuiteDirectory;

use function simplexml_load_string;

use SimpleXMLElement;

use function sprintf;
use function trim;

/**
 * The test suite a PHPUnit config declares: the `<directory>` and `<file>` of
 * every `<testsuite>`, less each `<exclude>`, as PHPUnit itself, and the
 * runners over it, read them, each directory with the suffix that tells its
 * files of test cases, and each `<testsuite>` by its name. A `<directory>`
 * with a wildcard is each directory it matches, as PHPUnit expands it.
 *
 * @internal
 */
final readonly class PhpUnitSuite
{
    private const string DIRECTORIES = '/phpunit/testsuites/testsuite/directory';

    private const string FILES = '/phpunit/testsuites/testsuite/file';

    private const string EXCLUDED = '/phpunit/testsuites/testsuite/exclude';

    private const string SUITES = '/phpunit/testsuites/testsuite';

    /** What a `<testsuite>` holds, read relative to it. */
    private const string DIRECTORY = './directory';

    private const string FILE = './file';

    private const string EXCLUDE = './exclude';

    private const string NOT_XML = '%s is not XML, so the test suite it declares cannot be read.';

    /** The suite a PHPUnit config, read from this file, declares, its wildcards expanded by a glob. */
    public static function declaredIn(Contents $config, Path $file, DirectoryGlob $glob): TestSuite|CannotJudge
    {
        $xml = simplexml_load_string($config->text(), options: Xml::QUIET);

        if (! $xml instanceof SimpleXMLElement) {
            return CannotJudge::because(sprintf(self::NOT_XML, $file->value()));
        }

        return TestSuite::of(
            self::directoriesIn($xml, self::DIRECTORIES, $glob),
            SuiteDirectory::conventional(),
            self::pathsIn($xml, self::FILES),
            self::excludedIn($xml, self::EXCLUDED, $glob),
            self::suitesIn($xml, $glob),
        );
    }

    /** Each `<testsuite>` of a config, by its name, with its own directories, files and excludes. */
    private static function suitesIn(SimpleXMLElement $xml, DirectoryGlob $glob): DeclaredSuites
    {
        $suites = [];

        foreach (self::nodesIn($xml, self::SUITES) as $suite) {
            $suites[] = DeclaredSuite::named(
                (string) $suite->attributes()?->name,
                self::pathsIn($suite, self::FILE),
                self::excludedIn($suite, self::EXCLUDE, $glob),
                ...self::directoriesIn($suite, self::DIRECTORY, $glob),
            );
        }

        return DeclaredSuites::of(...$suites);
    }

    /**
     * Each directory the `<directory>` nodes a query finds name, each
     * wildcard expanded, with the suffix of its node.
     *
     * @return list<SuiteDirectory>
     */
    private static function directoriesIn(SimpleXMLElement $xml, string $query, DirectoryGlob $glob): array
    {
        $directories = [];

        foreach (self::nodesIn($xml, $query) as $node) {
            $suffix = (string) $node->attributes()?->suffix;

            foreach ($glob->expanded(Path::of(trim((string) $node))) as $directory) {
                $directories[] = SuiteDirectory::of($directory, $suffix);
            }
        }

        return $directories;
    }

    /** @return list<SimpleXMLElement> */
    private static function nodesIn(SimpleXMLElement $xml, string $query): array
    {
        $nodes = $xml->xpath($query);

        return $nodes === false || $nodes === null ? [] : array_values($nodes);
    }

    /**
     * The paths the `<exclude>` nodes a query finds name, each wildcard
     * expanded into the directories it matches, as PHPUnit expands them.
     */
    private static function excludedIn(SimpleXMLElement $xml, string $query, DirectoryGlob $glob): Paths
    {
        $excluded = Paths::none();

        foreach (self::pathsIn($xml, $query) as $path) {
            $excluded = $excluded->and($glob->expanded($path));
        }

        return $excluded;
    }

    private static function pathsIn(SimpleXMLElement $xml, string $query): Paths
    {
        $paths = [];

        foreach (self::nodesIn($xml, $query) as $node) {
            $paths[] = Path::of(trim((string) $node));
        }

        return Paths::of(...$paths);
    }
}
