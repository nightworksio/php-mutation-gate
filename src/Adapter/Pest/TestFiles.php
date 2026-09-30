<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_any;
use function array_map;
use function is_array;
use function is_dir;
use function is_link;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

use function preg_replace;
use function scandir;
use function sprintf;
use function str_ends_with;

/**
 * The test files of a project, and which of them hold a class a filter's
 * `<Class>::` selects. Pest names a test file's class after its path, keeping
 * only its letters and digits, and PHPUnit names it after its file, so a file
 * is selected when that name ends in the class the filter names.
 */
final readonly class TestFiles
{
    /** What Pest removes from a file's name to make its class name. */
    private const array NOT_IN_A_CLASS_NAME = ['/%[a-fA-F0-9]{2}/', '/[^\p{L}\p{N}]/u'];

    /** Every PHP file under the project's test directories, each directory's entries in name order. */
    public static function in(Project $project): Paths
    {
        $found = [];

        foreach ($project->tests() as $directory) {
            $found = [...$found, ...self::under($project->absolute($directory))];
        }

        return Paths::of(...array_map($project->relative(...), $found));
    }

    /**
     * The files whose class name ends in one of these class names.
     *
     * @param list<string> $classes
     */
    public static function naming(Paths $files, array $classes): Paths
    {
        $named = Paths::none();

        foreach ($files as $file) {
            $name = $file->stem();
            $class = preg_replace(self::NOT_IN_A_CLASS_NAME, '', $name) ?? $name;

            if (array_any($classes, static fn(string $selected): bool => str_ends_with($class, $selected))) {
                $named = $named->with($file);
            }
        }

        return $named;
    }

    /** @return list<string> every PHP file under a directory, by its path on disk */
    private static function under(string $directory): array
    {
        $entries = is_dir($directory) ? scandir($directory) : [];
        $found = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            $path = sprintf('%s/%s', $directory, $entry);
            $found = [...$found, ...match (true) {
                $entry === '.' || $entry === '..' || (is_link($path) && is_dir($path)) => [],
                is_dir($path) => self::under($path),
                Path::of($entry)->isPhp() => [$path],
                default => [],
            }];
        }

        return $found;
    }
}
