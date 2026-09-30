<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Composer;

use function array_map;
use function array_values;
use function ltrim;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;

use function sprintf;

/**
 * Where a manifest's psr-4 and psr-0 autoload would look for a class, read
 * without loading anything: each file spelt from the repository's root.
 * Whether one is there is for the disk to say.
 */
final readonly class ClassLocations
{
    /** @param list<Node> $autoloads each `autoload` section, in the order the manifest's are read */
    private function __construct(private array $autoloads, private Path $directory)
    {
    }

    /** The locations each `autoload` section gives, in the order a manifest's are read, below its directory. */
    public static function of(Path $directory, Node ...$autoloads): self
    {
        return new self(array_values($autoloads), $directory);
    }

    /** The files each prefix that is the class's maps it to, in the order the sections list them. */
    public function files(string $class): Paths
    {
        $class = ltrim($class, '\\');
        $files = [];

        foreach ($this->autoloads as $autoload) {
            $files = [
                ...$files,
                ...$this->mapped($autoload, AutoloadKind::Psr4, $class),
                ...$this->mapped($autoload, AutoloadKind::Psr0, $class),
            ];
        }

        return Paths::of(...$files);
    }

    /**
     * The file each prefix of an autoload kind maps a class to, below each directory it names.
     *
     * @return list<Path>
     */
    private function mapped(Node $autoload, AutoloadKind $kind, string $class): array
    {
        $files = [];

        foreach (Lenient::entries($autoload->field($kind->value)) as $prefix => $entry) {
            $relative = $kind->fileOf($class, sprintf('%s', $prefix));
            $files = [...$files, ...($relative === '' ? [] : $this->under($entry, $relative))];
        }

        return $files;
    }

    /**
     * A file below each directory an autoload entry names: one, or a list of them.
     *
     * @return list<Path>
     */
    private function under(Node $entry, string $relative): array
    {
        $paths = [];

        foreach ([$entry, ...Lenient::items($entry)] as $place) {
            $directory = Lenient::text($place);
            $paths = $directory === '' ? $paths : [...$paths, $this->directory->child(Path::of($directory))];
        }

        return array_map(static fn(Path $root): Path => $root->child(Path::of($relative)), $paths);
    }
}
