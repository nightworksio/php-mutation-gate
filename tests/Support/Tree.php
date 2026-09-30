<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_map;

use Composer\Autoload\ClassLoader;

use function dirname;
use function glob;
use function is_dir;
use function mb_strlen;
use function realpath;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function sort;

use SplFileInfo;

use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function uksort;

/** The repository, as its files. */
final readonly class Tree
{
    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function at(string $path): string
    {
        return sprintf('%s/%s', self::root(), $path);
    }

    /**
     * The first-party plugins, each a directory under `plugins` with a
     * Composer manifest of its own (ADR-0021), in byte order.
     *
     * @return list<string>
     */
    public static function plugins(): array
    {
        $manifests = glob(self::at('plugins/*/composer.json'));

        return self::ownOnly(array_map(static fn(string $manifest): string => self::relative(dirname($manifest)), $manifests === false ? [] : $manifests));
    }

    /**
     * The trees of the code the package ships: src, and each plugin's src.
     *
     * @return list<string>
     */
    public static function shipped(): array
    {
        return ['src', ...array_map(static fn(string $plugin): string => sprintf('%s/src', $plugin), self::plugins())];
    }

    /**
     * Each directory Composer's PSR-4 autoloading maps, relative to the root
     * and ending in a slash, to its namespace prefix, the longest first.
     *
     * @return array<string, string>
     */
    public static function namespaces(): array
    {
        $found = [];
        $root = sprintf('%s/', realpath(self::root()));

        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            foreach ($loader->getPrefixesPsr4() as $namespace => $directories) {
                foreach ($directories as $directory) {
                    $found[sprintf('%s/', str_replace($root, '', (string) realpath($directory)))] = $namespace;
                }
            }
        }

        uksort($found, static fn(string $one, string $other): int => mb_strlen($other) <=> mb_strlen($one));

        return $found;
    }

    /** A path under the root, relative to it. */
    public static function relative(string $path): string
    {
        return str_replace(sprintf('%s/', self::root()), '', $path);
    }

    /**
     * Every file under a directory of the repository ending in a suffix, as
     * paths relative to the root, in byte order. A directory that is not there
     * holds nothing, and a vendor directory, such as the one the runner
     * contract suite's fixture installs, is another project's.
     *
     * @return list<string>
     */
    public static function filesUnder(string $directory, string $suffix = '.php'): array
    {
        if (! is_dir(self::at($directory))) {
            return [];
        }

        $found = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::at($directory), RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && str_ends_with($file->getPathname(), $suffix)) {
                $found[] = self::relative($file->getPathname());
            }
        }

        return self::ownOnly($found);
    }

    /**
     * The paths under no vendor directory, in byte order.
     *
     * @param  list<string> $paths
     * @return list<string>
     */
    private static function ownOnly(array $paths): array
    {
        $own = [];

        foreach ($paths as $path) {
            if (! str_contains($path, '/vendor/')) {
                $own[] = $path;
            }
        }

        sort($own);

        return $own;
    }
}
