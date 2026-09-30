<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_diff;
use function array_key_exists;
use function array_keys;
use function array_values;

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;

/**
 * The packages Composer lists as installed, read from the text of
 * `vendor/composer/installed.json`: each package's version and source
 * reference, the `source` one where it has one and the `dist` one otherwise.
 * An entry that is not a package with a name is no package.
 */
final readonly class Installed
{
    /** Where a package's source reference is, in the order Composer prefers them. */
    private const array ORIGINS = ['source', 'dist'];

    /** @param array<string, Version> $versions by package */
    private function __construct(private array $versions)
    {
    }

    public static function fromJson(string $json): self
    {
        $packages = Node::decode($json)->field('packages');
        $versions = [];

        foreach (self::itemsOf($packages) as $package) {
            $name = self::textOf($package->field('name'));
            $versions = [...$versions, ...($name === '' ? [] : [$name => self::versionOf($name, $package)])];
        }

        return new self($versions);
    }

    /**
     * The packages among these that Composer does not list, in their order.
     *
     * @return list<string>
     */
    public function missing(string ...$packages): array
    {
        return array_values(array_diff($packages, array_keys($this->versions)));
    }

    /** The versions of these packages, in their order, less any Composer does not list. */
    public function versionsOf(string ...$packages): Versions
    {
        $versions = Versions::none();

        foreach ($packages as $package) {
            $versions = array_key_exists($package, $this->versions)
                ? $versions->with($this->versions[$package])
                : $versions;
        }

        return $versions;
    }

    private static function versionOf(string $name, Node $package): Version
    {
        $reference = '';

        foreach (self::ORIGINS as $origin) {
            $reference = $reference === '' ? self::textOf($package->field($origin)->field('reference')) : $reference;
        }

        return Version::of($name, self::textOf($package->field('version')), $reference);
    }

    /** @return list<Node> */
    private static function itemsOf(Node $list): array
    {
        try {
            return $list->items();
        } catch (NotInShape) {
            return [];
        }
    }

    /** The text a place holds; none where it holds something else, or nothing. */
    private static function textOf(Node $place): string
    {
        try {
            return $place->text();
        } catch (NotInShape) {
            return '';
        }
    }
}
