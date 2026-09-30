<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use DOMDocument;
use DOMElement;
use DOMNameSpaceNode;
use DOMNode;
use DOMXPath;

use function file_put_contents;
use function is_file;
use function iterator_to_array;
use function ltrim;

use NightWorksIO\MutationGate\Core\CannotJudge;

use function sprintf;
use function str_replace;
use function str_starts_with;
use function trim;

/**
 * The PHPUnit config a run of no test starts with, shaped by the steps
 * Infection's MutationConfigBuilder takes for a mutant's own config that
 * change what a run loads: every path made absolute from the config's
 * directory, no loggers and no coverage reports, no colours, no default
 * suite, and the test suites replaced by one that holds the covering test
 * files, here none. Infection's builder is in the project's vendor, which
 * the gate's own process never loads, so the gate takes the same steps.
 */
final readonly class StartUpConfig
{
    /** Where the config is written, among the adapter's own files. */
    public const string FILE = 'start-up/phpunit.xml';

    /** The one suite it keeps, which holds no test file. */
    public const string SUITE = 'mutation-gate start-up';

    /** The nodes whose value is a path, as Infection's XmlConfigurationManipulator finds them. */
    private const string PATHS = '/phpunit/@bootstrap|/phpunit/testsuites/testsuite/exclude|//directory|//file';

    /** What Infection removes, as a mutant's run writes no log and no report. */
    private const string LOGGERS = '/phpunit/logging|/phpunit/coverage/report';

    /** The suites Infection replaces, in a `testsuites` element or on the root. */
    private const string SUITES = '/phpunit/testsuites|/phpunit/testsuite';

    private const string NONE = 'The run of no test needs PHPUnit\'s config, and there is none in %s.';

    private const string UNREADABLE = 'PHPUnit\'s config %s is not XML the gate can read.';

    /** The config written among the adapter's own files, from the project's PHPUnit config, or why it cannot be. */
    public static function written(Project $project, OwnConfig $config): string|CannotJudge
    {
        $original = self::original($project, $config);
        $document = $original instanceof CannotJudge
            ? $original
            : XmlFile::read($original, CannotJudge::because(sprintf(self::UNREADABLE, $original)));
        $file = $document instanceof CannotJudge ? $document : $project->fresh($project->own(self::FILE));

        return match (true) {
            $document instanceof CannotJudge => $document,
            $file instanceof CannotJudge => $file,
            default => self::saved(self::shaped($document, $config->configDirectory($project)), $file),
        };
    }

    /** The project's PHPUnit config, by the first of its names PHPUnit finds in its directory. */
    private static function original(Project $project, OwnConfig $config): string|CannotJudge
    {
        foreach ($config->phpUnitConfigs($project) as $candidate) {
            if (is_file($project->absolute($candidate))) {
                return $project->absolute($candidate);
            }
        }

        return CannotJudge::because(sprintf(self::NONE, $config->configDirectory($project)));
    }

    /** The file, once the document is written into it, as the adapter writes its other files. */
    private static function saved(DOMDocument $document, string $file): string
    {
        file_put_contents($file, $document->saveXML());

        return $file;
    }

    private static function shaped(DOMDocument $document, string $directory): DOMDocument
    {
        $xpath = new DOMXPath($document);

        foreach (self::all($xpath, self::PATHS) as $node) {
            $node->nodeValue = self::absolute(trim((string) $node->nodeValue), $directory);
        }

        foreach ([...self::all($xpath, self::LOGGERS), ...self::all($xpath, self::SUITES)] as $node) {
            if ($node instanceof DOMNode) {
                $node->parentNode?->removeChild($node);
            }
        }

        $root = $document->documentElement;

        if ($root instanceof DOMElement) {
            $suites = new DOMElement('testsuites');
            $suite = new DOMElement('testsuite');
            $root->setAttribute('colors', 'false');
            $root->removeAttribute('defaultTestSuite');
            $root->appendChild($suites);
            $suites->appendChild($suite);
            $suite->setAttribute('name', self::SUITE);
        }

        return $document;
    }

    /** @return list<DOMNameSpaceNode|DOMNode> */
    private static function all(DOMXPath $xpath, string $query): array
    {
        $found = $xpath->query($query);

        return $found === false ? [] : iterator_to_array($found, preserve_keys: false);
    }

    /** A path from the config's directory, as Infection's PathReplacer writes it; an absolute path as it is. */
    private static function absolute(string $path, string $directory): string
    {
        return str_starts_with($path, '/')
            ? $path
            : str_replace('/./', '/', sprintf('%s/%s', $directory, ltrim($path, '\\/')));
    }

}
