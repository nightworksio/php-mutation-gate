<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_any;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_push;
use function array_unique;
use function implode;
use function is_array;
use function is_dir;
use function is_link;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

use function preg_replace;
use function scandir;
use function sort;
use function sprintf;
use function str_ends_with;

/**
 * The test files of a project, and which of them hold a class a filter's
 * `<Class>::` selects. Pest names a test file's class after its path, keeping
 * only its letters and digits, and PHPUnit names it after its file, so a file
 * is selected when that name ends in the class the filter names.
 *
 * It lists the files, and names each one's class, once, and answers each set
 * of classes once: many units share a set, and a project holds thousands of
 * test files.
 */
final class TestFiles
{
    /** What Pest removes from a file's name to make its class name. */
    private const array NOT_IN_A_CLASS_NAME = ['/%[a-fA-F0-9]{2}/', '/[^\p{L}\p{N}]/u'];

    /** @var list<array<string, string>> each test file's class name, by its path, once listed */
    private array $listed = [];

    /** @var array<string, Paths> the files each set of classes names, by the set */
    private array $named = [];

    public function __construct(private readonly Project $project)
    {
    }

    /** Every PHP file under the project's test directories, each directory's entries in name order. */
    public function all(): Paths
    {
        return Paths::of(...array_map(Path::of(...), array_keys($this->classes())));
    }

    /**
     * The files whose class name ends in one of these class names.
     *
     * @param list<string> $classes
     */
    public function naming(array $classes): Paths
    {
        $asked = array_unique($classes);
        sort($asked);
        $key = implode("\n", $asked);

        if (! array_key_exists($key, $this->named)) {
            $named = [];

            foreach ($this->classes() as $file => $class) {
                if (array_any($asked, static fn(string $selected): bool => str_ends_with($class, $selected))) {
                    $named[] = Path::of($file);
                }
            }

            $this->named[$key] = Paths::of(...$named);
        }

        return $this->named[$key];
    }

    /** @return array<string, string> each test file's class name, by its path as the project spells it */
    private function classes(): array
    {
        if ($this->listed === []) {
            $classes = [];

            foreach ($this->project->tests() as $directory) {
                foreach (self::under($this->project->absolute($directory)) as $found) {
                    $path = $this->project->relative($found);
                    $name = $path->stem();
                    $classes[$path->value()] = preg_replace(self::NOT_IN_A_CLASS_NAME, '', $name) ?? $name;
                }
            }

            $this->listed = [$classes];
        }

        return $this->listed[0];
    }

    /** @return list<string> the PHP files an entry of a directory is, or holds, by their paths on disk */
    private static function entry(string $directory, string $entry): array
    {
        $path = sprintf('%s/%s', $directory, $entry);

        return match (true) {
            $entry === '.' || $entry === '..' || (is_link($path) && is_dir($path)) => [],
            is_dir($path) => self::under($path),
            Path::of($entry)->isPhp() => [$path],
            default => [],
        };
    }

    /** @return list<string> every PHP file under a directory, by its path on disk */
    private static function under(string $directory): array
    {
        $entries = is_dir($directory) ? scandir($directory) : [];
        $found = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            array_push($found, ...self::entry($directory, $entry));
        }

        return $found;
    }
}
