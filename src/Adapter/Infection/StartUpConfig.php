<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function basename;
use function copy;

use DOMDocument;
use DOMElement;
use DOMNameSpaceNode;
use DOMNode;
use DOMXPath;

use function file_put_contents;
use function is_file;
use function is_string;
use function iterator_to_array;
use function ltrim;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\File\Path;

use function preg_match;
use function realpath;
use function sprintf;
use function str_replace;
use function trim;
use function var_export;

/**
 * The PHPUnit config a run of no test starts with, shaped by the steps
 * Infection's MutationConfigBuilder takes for a mutant's own config that
 * change what a run loads: every path made absolute from the config's
 * directory, no loggers and no coverage reports, no colours, no printer, no
 * default suite, the test suites replaced by one that holds the covering
 * test files, here none, and its bootstrap replaced by Infection's: one that
 * lowers the process's priority, serves the mutant's file through
 * Infection's include interceptor, here an unchanged copy, and then loads
 * the original bootstrap. The steps that only order, stop or report a run
 * are left out. Infection's builder is in the project's vendor, which the
 * gate's own process never loads, so the gate takes the same steps.
 */
final readonly class StartUpConfig
{
    /** Where the config is written, among the adapter's own files. */
    public const string FILE = 'start-up/phpunit.xml';

    /** The one suite it keeps, which holds no test file. */
    public const string SUITE = 'mutation-gate start-up';

    /** Where the bootstrap Infection gives a mutant's run is written, among the adapter's own files. */
    private const string BOOTSTRAP = 'start-up/interceptor.autoload.php';

    /** Where the unchanged copy is written, among the adapter's own files. */
    private const string COPY = 'start-up/%s';

    /** Infection's include interceptor, where Composer installs it beside Infection. */
    private const string INTERCEPTOR = 'infection/include-interceptor/src/IncludeInterceptor.php';

    /** The nodes whose value is a path, as Infection's XmlConfigurationManipulator finds them. */
    private const string PATHS = '/phpunit/@bootstrap|/phpunit/testsuites/testsuite/exclude|//directory|//file';

    /** What Infection removes, as a mutant's run writes no log and no report. */
    private const string LOGGERS = '/phpunit/logging|/phpunit/coverage/report';

    /** The suites Infection replaces, in a `testsuites` element or on the root. */
    private const string SUITES = '/phpunit/testsuites|/phpunit/testsuite';

    /** An absolute path, as Symfony's Filesystem::isAbsolutePath reads one: a root, a drive or a scheme. */
    private const string ABSOLUTE = '~^([/\\\\]|[a-z]:[/\\\\]|[a-z][a-z0-9+.-]*://)~i';

    /** The bootstrap Infection writes for a mutant's run (see MutationConfigBuilder). */
    private const string INTERCEPTING = <<<'PHP'
        <?php
        if (function_exists('proc_nice')) {
            proc_nice(1);
        }

        require_once %s;
        use Infection\StreamWrapper\IncludeInterceptor;
        IncludeInterceptor::intercept(%s, %s);
        IncludeInterceptor::enable();
        require_once %s;
        PHP;

    private const string NONE = 'The run of no test needs PHPUnit\'s config, and there is none in %s.';

    private const string UNREADABLE = 'PHPUnit\'s config %s is not XML the gate can read.';

    private const string NOT_COPIED = 'Infection\'s run of no test needs an unchanged copy of %s, which was not made.';

    /**
     * The config written among the adapter's own files, from the project's
     * PHPUnit config, its mutant an unchanged copy of this file, or why it
     * cannot be.
     */
    public static function written(Project $project, OwnConfig $config, Path $file): string|CannotJudge
    {
        $original = self::original($project, $config);
        $read = $original instanceof CannotJudge
            ? $original
            : XmlFile::read($original, CannotJudge::because(sprintf(self::UNREADABLE, $original)));
        $document = $read instanceof CannotJudge ? $read : self::shaped($read, $config->configDirectory($project));
        $written = $document instanceof CannotJudge ? $document : $project->fresh($project->own(self::FILE));
        $bootstrap = $written instanceof CannotJudge ? $written : self::intercepting($project, $file, $document);

        return match (true) {
            $document instanceof CannotJudge => $document,
            $written instanceof CannotJudge => $written,
            $bootstrap instanceof CannotJudge => $bootstrap,
            default => self::saved(self::bootstrapped($document, $bootstrap), $written),
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

    /**
     * The bootstrap Infection gives a mutant's run, written among the
     * adapter's own files: it serves an unchanged copy of the file in the
     * file's place, then loads the config's own bootstrap, or the project's
     * autoloader where the config names none.
     */
    private static function intercepting(Project $project, Path $file, DOMDocument $document): string|CannotJudge
    {
        $original = realpath($project->absolute($file));
        $copy = $project->fresh($project->own(sprintf(self::COPY, basename($file->value()))));
        $bootstrap = $project->fresh($project->own(self::BOOTSTRAP));
        $interceptor = $project->absolute(Path::of(sprintf('%s/%s', Manifest::VENDOR, self::INTERCEPTOR)));

        return match (true) {
            $copy instanceof CannotJudge => $copy,
            $bootstrap instanceof CannotJudge => $bootstrap,
            $original === false => CannotJudge::because(sprintf(self::NOT_COPIED, $file->value())),
            default => self::savedText(
                $bootstrap,
                sprintf(
                    self::INTERCEPTING,
                    var_export($interceptor, return: true),
                    var_export($original, return: true),
                    var_export(self::copied($original, $copy), return: true),
                    var_export(self::loaded($project, $document), return: true),
                ),
            ),
        };
    }

    /** The bootstrap the config names, made absolute, or the project's autoloader. */
    private static function loaded(Project $project, DOMDocument $document): string
    {
        $named = new DOMXPath($document)->evaluate('string(/phpunit/@bootstrap)');

        return $named === '' || ! is_string($named)
            ? $project->absolute(Path::of(sprintf('%s/autoload.php', Manifest::VENDOR)))
            : $named;
    }

    /** The config, starting from this bootstrap, as Infection's setCustomBootstrapPath sets it. */
    private static function bootstrapped(DOMDocument $document, string $bootstrap): DOMDocument
    {
        $document->documentElement?->setAttribute('bootstrap', $bootstrap);

        return $document;
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
            $root->removeAttribute('printerClass');
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
        return preg_match(self::ABSOLUTE, $path) === 1
            ? $path
            : str_replace('/./', '/', sprintf('%s/%s', $directory, ltrim($path, '\\/')));
    }

    /** The file, once the document is written into it, as the adapter writes its other files. */
    private static function saved(DOMDocument $document, string $file): string
    {
        file_put_contents($file, $document->saveXML());

        return $file;
    }

    /** The copy, once the original is copied into it, as the adapter writes its other files. */
    private static function copied(string $original, string $copy): string
    {
        copy($original, $copy);

        return $copy;
    }

    private static function savedText(string $file, string $text): string
    {
        file_put_contents($file, $text);

        return $file;
    }
}
