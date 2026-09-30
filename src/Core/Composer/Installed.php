<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Composer;

use function array_diff;
use function array_key_exists;
use function array_keys;
use function array_values;

use ArrayIterator;

use function implode;

use IteratorAggregate;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;

use function sprintf;

use Traversable;

/**
 * The packages Composer lists as installed, read from the text of its
 * `vendor/composer/installed.json`: each package's manifest, and the version
 * and source reference of each that has a name, the `source` reference where
 * it has one and the `dist` one otherwise. An entry that is not a map is no
 * package.
 *
 * @implements IteratorAggregate<int, Manifest>
 */
final readonly class Installed implements IteratorAggregate
{
    /** Where a package's source reference is, in the order Composer prefers them. */
    private const array ORIGINS = ['source', 'dist'];

    private const string NOT_A_LIST
        = '%s is not the list of installed packages Composer 2 writes, so what it installed cannot be read.';

    private const string UNLISTED
        = '%s does not list %s, so the gate cannot say which %s judges the mutants. Run composer install.';

    /**
     * @param list<Manifest>         $packages every package, in the order Composer lists them
     * @param array<string, Version> $versions by package
     */
    private function __construct(private Path $file, private array $packages, private array $versions)
    {
    }

    /** The list in a file, from its text, or why it cannot be read: it is not the one Composer 2 writes. */
    public static function decode(Contents $contents, Path $file): self|CannotJudge
    {
        try {
            $packages = Node::decode($contents->text())->field('packages')->items();
        } catch (NotInShape) {
            return CannotJudge::because(sprintf(self::NOT_A_LIST, $file->value()));
        }

        $manifests = [];
        $versions = [];

        foreach ($packages as $package) {
            $manifest = Manifest::installed($package, $file);
            $name = $manifest->name();
            $manifests = Lenient::entries($package) === [] ? $manifests : [...$manifests, $manifest];
            $versions = $name instanceof Unnamed
                ? $versions
                : [...$versions, $name => self::versionOf($name, $package)];
        }

        return new self($file, $manifests, $versions);
    }

    /** The list where the file is not there: Composer has installed nothing. */
    public static function missingAt(Path $file): self
    {
        return new self($file, [], []);
    }

    /** Whether Composer lists a package. */
    public function has(string $package): bool
    {
        return array_key_exists($package, $this->versions);
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

    /**
     * The versions of the packages a runner drives, or why the gate cannot
     * say which of that runner judges the mutants: Composer lists not all of them.
     */
    public function drivenBy(string $runner, string ...$packages): Versions|CannotJudge
    {
        $missing = $this->missing(...$packages);

        return $missing === []
            ? $this->versionsOf(...$packages)
            : CannotJudge::because(sprintf(self::UNLISTED, $this->file->value(), implode(', ', $missing), $runner));
    }

    /** @return Traversable<int, Manifest> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->packages);
    }

    private static function versionOf(string $name, Node $package): Version
    {
        $reference = '';

        foreach (self::ORIGINS as $origin) {
            $reference = $reference === '' ? Lenient::text($package->field($origin)->field('reference')) : $reference;
        }

        return Version::of($name, Lenient::text($package->field('version')), $reference);
    }
}
