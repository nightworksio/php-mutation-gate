<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Composer;

use function array_filter;
use function array_keys;
use function array_values;
use function dirname;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;

use function sprintf;

/**
 * A `composer.json`, or one package's entry in Composer's list of what it
 * installed, read for what the gate asks of it: its name, the paths its
 * autoload names, the packages it requires, its path repositories, where it
 * installs packages, and its `extra.mutation-gate` entry. What holds another shape than Composer's
 * reads as holding nothing.
 */
final readonly class Manifest
{
    /** Where Composer installs a project's packages when nothing names another directory. */
    public const string VENDOR = 'vendor';

    /** The file every project, module and package declares itself in. */
    private const string FILE = 'composer.json';

    /** The keys whose values are the packages a manifest requires to run. */
    private const string REQUIRE = 'require';

    /** The key whose value is the packages it requires only to be developed. */
    private const string REQUIRE_DEV = 'require-dev';

    /** The repository type whose `url` is a directory, or a shell glob of directories. */
    private const string PATH = 'path';

    private const string NOT_AN_OBJECT = '%s is not a JSON object.';

    private function __construct(private Node $manifest, private Path $file)
    {
    }

    /** Where the manifest of a directory is. */
    public static function fileIn(Path $directory): Path
    {
        return Path::of(sprintf('%s/%s', $directory->value(), self::FILE));
    }

    /** The manifest of a directory, from its text, or why it cannot be read: text that is not a JSON object. */
    public static function decode(Contents $contents, Path $directory): self|CannotJudge
    {
        $manifest = Node::decode($contents->text());
        $file = self::fileIn($directory);

        try {
            $manifest->entries();
        } catch (NotInShape) {
            return CannotJudge::because(sprintf(self::NOT_AN_OBJECT, $file->value()));
        }

        return new self($manifest, $file);
    }

    /** One package's entry in Composer's list of what it installed, which is in this file. */
    public static function installed(Node $package, Path $installed): self
    {
        return new self($package, $installed);
    }

    /** The file the manifest was read from. */
    public function file(): Path
    {
        return $this->file;
    }

    /** Where Composer installs the project's packages: its `config.vendor-dir`, or `vendor`. */
    public function vendorDirectory(): Path
    {
        $declared = Lenient::text($this->manifest->field('config')->field('vendor-dir'));

        return Path::of($declared === '' ? self::VENDOR : $declared);
    }

    /** The package's name, where it declares one. */
    public function name(): string|Unnamed
    {
        $name = Lenient::text($this->manifest->field('name'));

        return $name === '' ? Unnamed::package() : $name;
    }

    /** The package's name, or the file it was read from where it declares none, as a message names it. */
    public function origin(): string
    {
        $name = $this->name();

        return $name instanceof Unnamed ? $this->file->value() : $name;
    }

    /**
     * Every path a `composer.json`'s `autoload` names, not `autoload-dev`,
     * spelt from the repository's root, in its order.
     */
    public function autoloaded(): Paths
    {
        $directory = dirname($this->file->value());
        $paths = [];

        foreach (AutoloadKind::cases() as $kind) {
            foreach (Lenient::entries($this->manifest->field('autoload')->field($kind->value)) as $entry) {
                $paths = [...$paths, ...self::pathsIn($entry, $directory)];
            }
        }

        return Paths::of(...$paths);
    }

    /** Every package it requires, to run or to be developed, by name, in its order. */
    public function requires(): Names
    {
        return Names::of(...$this->requiredUnder(self::REQUIRE), ...$this->requiredUnder(self::REQUIRE_DEV));
    }

    /** Every package it requires to run, by name, in its order. */
    public function requiresToRun(): Names
    {
        return Names::of(...$this->requiredUnder(self::REQUIRE));
    }

    /** The `url` of every repository of type `path`: each a directory, or a shell glob of directories. */
    public function pathRepositories(): Paths
    {
        $urls = [];

        foreach (Lenient::items($this->manifest->field('repositories')) as $repository) {
            $url = Lenient::text($repository->field('url'));
            $isPath = Lenient::text($repository->field('type')) === self::PATH && $url !== '';
            $urls = $isPath ? [...$urls, Path::of($url)] : $urls;
        }

        return Paths::of(...$urls);
    }

    /** Its `extra.mutation-gate` entry. */
    public function gate(): GateEntry
    {
        return GateEntry::in($this->manifest, $this->file, $this->origin());
    }

    /** Its text with the `extra.mutation-gate` entry taken out, and every other member as it was read. */
    public function withoutGateEntry(): Contents
    {
        return Contents::of(GateEntry::removedFrom($this->manifest));
    }

    /**
     * The path an autoload entry names, or each path of a list of them, spelt from the repository's root.
     *
     * @return list<Path>
     */
    private static function pathsIn(Node $entry, string $directory): array
    {
        $paths = [];

        foreach ([$entry, ...Lenient::items($entry)] as $place) {
            $path = Lenient::text($place);
            $paths = $path === '' ? $paths : [...$paths, Path::of(sprintf('%s/%s', $directory, $path))];
        }

        return $paths;
    }

    /**
     * The names a map of requirements holds; a list holds none.
     *
     * @return list<string>
     */
    private function requiredUnder(string $key): array
    {
        return array_values(array_filter(array_keys(Lenient::entries($this->manifest->field($key))), is_string(...)));
    }
}
