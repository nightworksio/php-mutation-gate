<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Project;

use function array_any;
use function array_find;
use function count;
use function file_get_contents;
use function is_array;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Port\TreeSource;

use function simplexml_load_string;

use SimpleXMLElement;

use function sprintf;
use function str_starts_with;
use function trim;

/**
 * The `phpunit` tree source (ADR-0002, ADR-0005): one tree per `<directory>`
 * and `<file>` under `<source><include>` of the `phpunit.xml` PHPUnit itself
 * would read, less what `<source><exclude>` leaves out. A path excluded
 * inside a tree is a tree of its own with a floor of 0, so it is never
 * mutated and every run says why. Without an `<include>`, the preset's
 * trees: the fallback paths, or the `autoload` paths of `composer.json`.
 */
final readonly class PhpUnitTrees implements TreeSource
{
    private const string ALL_EXCLUDED
        = 'phpunit.xml excludes every path its <source> includes, so no tree is left; list trees in the config.';

    /** The files PHPUnit reads its configuration from, in the order it looks for them. */
    private const array CONFIGS = ['phpunit.xml', 'phpunit.dist.xml', 'phpunit.xml.dist'];

    private const string INCLUDED = '/phpunit/source/include/directory | /phpunit/source/include/file';

    private const string EXCLUDED = '/phpunit/source/exclude/directory | /phpunit/source/exclude/file';

    private const string TESTS = '/phpunit/testsuites/testsuite/directory';

    private function __construct(private string $root, private Paths $fallback, private Manifests $manifests)
    {
    }

    /** The tree source of the project at a root, with the paths it falls back on. */
    public static function in(string $root, Paths $fallback): self
    {
        return new self($root, $fallback, Manifests::in($root));
    }

    public function trees(): Trees|CannotJudge
    {
        $xml = $this->xml();

        if ($xml instanceof CannotJudge) {
            return $xml;
        }

        $included = $this->paths($xml, self::INCLUDED);

        return count($included) > 0 ? $this->source($included, $this->paths($xml, self::EXCLUDED)) : $this->fallback();
    }

    /** The `<directory>` of every `<testsuite>`, where the tests are. */
    public function testDirectories(): Paths|CannotJudge
    {
        $xml = $this->xml();

        return $xml instanceof CannotJudge ? $xml : $this->paths($xml, self::TESTS);
    }

    /** The paths that are, or are not, inside one of the others. */
    private function within(Paths $paths, Paths $others, bool $inside): Paths
    {
        $within = [];

        foreach ($paths as $path) {
            if ($this->isInAny($path, $others) === $inside) {
                $within[] = $path;
            }
        }

        return Paths::of(...$within);
    }

    private function source(Paths $included, Paths $excluded): Trees|CannotJudge
    {
        $kept = $this->within($included, $excluded, inside: false);
        $exempt = $this->within($excluded, $kept, inside: true);
        $trees = count($kept) > 0
            ? $this->manifests->trees($kept)
            : CannotJudge::because(self::ALL_EXCLUDED);

        if ($trees instanceof CannotJudge) {
            return $trees;
        }

        $exempted = [];

        foreach ($exempt as $path) {
            $exempted[] = Tree::at(
                $path,
                Exempt::because('phpunit.xml excludes it from <source>'),
                Package::at(Path::root()),
            );
        }

        return Trees::of(...$trees, ...$exempted);
    }

    private function fallback(): Trees|CannotJudge
    {
        $paths = count($this->fallback) > 0 ? $this->fallback : $this->manifests->autoloaded();

        return match (true) {
            $paths instanceof CannotJudge => $paths,
            count($paths) === 0 => CannotJudge::because(
                'No tree found in the <source> of phpunit.xml or the autoload of composer.json; list trees in config.',
            ),
            default => $this->manifests->trees($paths),
        };
    }

    private function xml(): SimpleXMLElement|Absent|CannotJudge
    {
        $config = array_find(self::CONFIGS, fn(string $name): bool => is_file(sprintf('%s/%s', $this->root, $name)));

        if (! is_string($config)) {
            return Absent::setting();
        }

        $xml = simplexml_load_string(
            sprintf('%s', file_get_contents(sprintf('%s/%s', $this->root, $config))),
            options: LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        return $xml instanceof SimpleXMLElement
            ? $xml
            : CannotJudge::because(sprintf('%s is not XML, so the trees and tests in it cannot be read.', $config));
    }

    private function paths(SimpleXMLElement|Absent $xml, string $query): Paths
    {
        $nodes = $xml instanceof SimpleXMLElement ? $xml->xpath($query) : [];
        $paths = [];

        foreach (is_array($nodes) ? $nodes : [] as $node) {
            $paths[] = Path::of(trim((string) $node));
        }

        return Paths::of(...$paths);
    }

    /** Whether a path is one of these, or inside one. */
    private function isInAny(Path $path, Paths $directories): bool
    {
        return array_any(
            [...$directories],
            static fn(Path $directory): bool => $directory->value() === '.'
                || str_starts_with(sprintf('%s/', $path->value()), sprintf('%s/', $directory->value())),
        );
    }
}
