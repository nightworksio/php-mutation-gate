<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Project;

use function array_any;
use function array_find;
use function count;
use function file_get_contents;
use function is_array;
use function is_file;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitConfig;
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

    private const string NOT_XML = '%s is not XML, so the trees and tests in it cannot be read.';

    private const string INCLUDED = '/phpunit/source/include/directory | /phpunit/source/include/file';

    private const string EXCLUDED = '/phpunit/source/exclude/directory | /phpunit/source/exclude/file';

    private const string TESTS = '/phpunit/testsuites/testsuite/directory';

    private function __construct(private Root $root, private Paths $fallback, private Manifests $manifests)
    {
    }

    /**
     * The tree source of the project at a root, with the paths it falls back
     * on. It takes the root as the registration spells it (owner: config).
     */
    public static function in(string $root, Paths $fallback): self
    {
        $typed = Root::of($root);

        return new self($typed, $fallback, Manifests::in($typed));
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

    /** The project's PHPUnit config, read; missing, by the first name it could have, where it has none. */
    private function xml(): SimpleXMLElement|Missing|CannotJudge
    {
        $candidates = [...PhpUnitConfig::candidatesIn(Path::root())];
        $config = array_find(
            $candidates,
            fn(Path $candidate): bool => is_file($this->root->at($candidate)->value()),
        );

        if (! $config instanceof Path) {
            return Missing::at($candidates[0]);
        }

        $xml = simplexml_load_string(
            sprintf('%s', file_get_contents($this->root->at($config)->value())),
            options: LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        return $xml instanceof SimpleXMLElement
            ? $xml
            : CannotJudge::because(sprintf(self::NOT_XML, $config->value()));
    }

    private function paths(SimpleXMLElement|Missing $xml, string $query): Paths
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
