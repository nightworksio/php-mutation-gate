<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use function array_key_exists;
use function array_keys;
use function array_map;
use function array_slice;

use Closure;
use Composer\InstalledVersions;

use function count;
use function dirname;
use function get_included_files;
use function getenv;
use function getrusage;
use function is_string;
use function json_encode;

use NightWorksIO\MutationGate\Core\Runner\ChildVariable;

use function realpath;
use function strval;

/**
 * What a process of a coverage run loaded and started as its tests ran (ADR-0113): the Composer packages whose files it
 * first loaded since it was last asked, mapped through what Composer installed, and whether a process it started has
 * ended since. `getrusage()` of the children counts every process waited for, whatever program it ran.
 *
 * @internal
 */
final class PackageWatch
{
    /** Asks `getrusage()` for the processes this one started and waited for. */
    private const int CHILDREN = 1;

    private int $read = 0;

    /** What the processes this one waited for had used when it last asked. */
    private string $children;

    /**
     * @param array<string, string> $held each package's directory, with its name
     * @param Closure(): list<string>         $included
     * @param Closure(): string $started
     */
    private function __construct(
        private array $held,
        private readonly Closure $included,
        private readonly Closure $started,
    ) {
        $this->children = ($this->started)();
    }

    /** A watch where the gate asks for one, over what Composer installed. */
    public static function fromEnvironment(): self|Off
    {
        return getenv(ChildVariable::RecordsLoads->value) === false
            ? Off::WatchingPackages
            : self::over(
                self::installed(),
                get_included_files(...),
                self::children(...),
            );
    }

    /**
     * @param array<string, string>           $installed each package's directory, with its name
     * @param Closure(): list<string>         $included
     * @param Closure(): string $started
     */
    public static function over(array $installed, Closure $included, Closure $started): self
    {
        return new self($installed, $included, $started);
    }

    /**
     * Each package first loaded since this was last asked.
     *
     * @return list<string>
     */
    public function newlyLoaded(): array
    {
        $included = ($this->included)();
        $packages = [];

        foreach (array_slice($included, $this->read) as $file) {
            $package = $this->holding(dirname($file));
            $packages[$package] = true;
        }

        $this->read = count($included);
        unset($packages['']);

        return array_map(strval(...), array_keys($packages));
    }

    /** Whether a process this one started ended since this was last asked. */
    public function startedAProcess(): bool
    {
        $children = ($this->started)();
        $started = $children !== $this->children;
        $this->children = $children;

        return $started;
    }

    /** What `getrusage()` says of the processes this one waited for. */
    private static function children(): string
    {
        return (string) json_encode(getrusage(self::CHILDREN));
    }

    /** The package that holds a directory: the nearest of its parents Composer installed one in; none where none is. */
    private function holding(string $directory): string
    {
        if (array_key_exists($directory, $this->held)) {
            return $this->held[$directory];
        }

        $parent = dirname($directory);
        $package = $parent === $directory ? '' : $this->holding($parent);
        $this->held[$directory] = $package;

        return $package;
    }

    /** @return array<string, string> every package Composer installed, but the root, by its directory */
    private static function installed(): array
    {
        $root = InstalledVersions::getRootPackage()['name'];
        $installed = [];

        foreach (InstalledVersions::getInstalledPackages() as $name) {
            $path = $name === $root ? null : InstalledVersions::getInstallPath($name);
            $real = is_string($path) ? realpath($path) : false;
            $installed = is_string($real) ? [...$installed, $real => $name] : $installed;
        }

        return $installed;
    }
}
