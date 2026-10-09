<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_any;
use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function dirname;
use function is_dir;

use NightWorksIO\MutationGate\Core\File\Paths;

use function sprintf;
use function str_starts_with;

/**
 * What a run mutates, as Infection is told it: the paths it is given, and
 * the source directories they are in, none inside another, since Infection
 * finds a file once for each source directory that holds it. Where a run
 * leaves paths out, it is given the PHP files left, one by one.
 */
final readonly class Targets
{
    /**
     * @param list<string> $paths       by their paths on disk
     * @param list<string> $directories by their paths on disk
     */
    private function __construct(private array $paths, private array $directories)
    {
    }

    public static function of(Project $project, Paths $files, Paths $leftOut): self
    {
        $asked = array_map($project->absolute(...), [...$files]);
        $out = array_map($project->absolute(...), [...$leftOut]);
        $directories = array_map(static fn(string $path): string => is_dir($path) ? $path : dirname($path), $asked);

        $paths = count($leftOut) === 0 ? $asked : array_values(array_filter(
            PhpFiles::in($project, $files),
            static fn(string $file): bool => ! array_any(
                $out,
                static fn(string $left): bool => self::inside($file, $left),
            ),
        ));

        return new self($paths, self::outermost(array_values(array_unique($directories))));
    }

    /** @return list<string> */
    public function paths(): array
    {
        return $this->paths;
    }

    /** @return list<string> */
    public function directories(): array
    {
        return $this->directories;
    }

    /**
     * The directories, less each inside another of them.
     *
     * @param  list<string> $directories
     * @return list<string>
     */
    private static function outermost(array $directories): array
    {
        return array_values(array_filter(
            $directories,
            static fn(string $directory): bool => ! array_any(
                $directories,
                static fn(string $other): bool => $other !== $directory && self::inside($directory, $other),
            ),
        ));
    }

    private static function inside(string $file, string $path): bool
    {
        return $file === $path || str_starts_with($file, sprintf('%s/', $path));
    }
}
