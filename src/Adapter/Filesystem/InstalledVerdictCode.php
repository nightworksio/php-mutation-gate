<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use function array_any;
use function array_filter;
use function array_values;

use Closure;
use Composer\InstalledVersions;

use function file_get_contents;

use FilesystemIterator;

use function hash_update;
use function is_dir;
use function is_file;
use function is_string;
use function ksort;
use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Key\VerdictCode;
use NightWorksIO\MutationGate\Core\ThisPackage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function sprintf;
use function str_starts_with;

/**
 * The digest of the gate's own code a proof's result depends on, as Composer
 * installed it (ADR-0084): each file of the sets a runner reads, and the
 * code of each of the gate's mutator packages, by its path and its
 * contents' digest.
 *
 * @internal
 */
final readonly class InstalledVerdictCode
{
    /**
     * @param Closure(string): (string|null) $installPath where a package is installed, by its name; none where it
     *                                                    is not
     * @param list<string>                  $packages    every package installed
     */
    private function __construct(private Closure $installPath, private array $packages)
    {
    }

    /** The gate's code as Composer installed it, beside every other package it installed. */
    public static function installed(): self
    {
        return new self(InstalledVersions::getInstallPath(...), InstalledVersions::getInstalledPackages());
    }

    /**
     * The gate's code where these packages are installed, each found by its name at the path the closure gives.
     *
     * @param Closure(string): (string|null) $installPath
     */
    public static function at(Closure $installPath, string ...$packages): self
    {
        return new self($installPath, array_values($packages));
    }

    /** The digest of what a run of this runner's proofs depend on. */
    public function of(string $runner): Digest
    {
        $digests = $this->digestsIn(ThisPackage::COMPOSER, VerdictCode::of($runner));

        foreach ($this->plugins() as $plugin) {
            $digests = [...$digests, ...$this->digestsIn($plugin, [VerdictCode::pluginCode()])];
        }

        ksort($digests);
        $context = Digest::hashing();

        foreach ($digests as $path => $digest) {
            hash_update($context, sprintf("%s\0%s\n", $path, $digest));
        }

        return Digest::finished($context);
    }

    /** @return list<string> the gate's mutator packages installed */
    private function plugins(): array
    {
        return array_values(array_filter(
            $this->packages,
            static fn(string $name): bool => str_starts_with($name, VerdictCode::PLUGINS),
        ));
    }

    /**
     * Each file of a package these globs match, by the package's name and its
     * path in it, with its contents' digest; none where it is not installed.
     *
     * @param  list<Glob>            $globs
     * @return array<string, string>
     */
    private function digestsIn(string $package, array $globs): array
    {
        $root = ($this->installPath)($package);
        $digests = [];
        $bases = [];

        if (! is_string($root)) {
            return [];
        }

        foreach ($globs as $glob) {
            $bases[$glob->base()->value()] = $glob->base()->value();
        }

        foreach ($bases as $base) {
            foreach ($this->filesUnder(sprintf('%s/%s', $root, $base)) as $file) {
                $path = mb_substr($file->getPathname(), mb_strlen($root) + 1);
                $matched = array_any($globs, static fn(Glob $glob): bool => $glob->matches(Path::of($path)));
                $named = sprintf('%s/%s', $package, $path);
                $digests = $matched ? [...$digests, $named => $this->digestOf($file)] : $digests;
            }
        }

        return $digests;
    }

    private function digestOf(SplFileInfo $file): string
    {
        return Digest::sha256Of((string) file_get_contents($file->getPathname()))->value();
    }

    /** @return list<SplFileInfo> each file at or under a path */
    private function filesUnder(string $at): array
    {
        if (is_file($at)) {
            return [new SplFileInfo($at)];
        }

        $files = [];
        $found = is_dir($at)
            ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($at, FilesystemIterator::SKIP_DOTS))
            : [];

        foreach ($found as $file) {
            $files = $file instanceof SplFileInfo && $file->isFile() ? [...$files, $file] : $files;
        }

        return $files;
    }
}
