<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use function array_any;
use function array_diff;
use function array_filter;
use function array_key_exists;
use function array_map;
use function array_push;
use function array_values;
use function clearstatcache;

use Closure;
use Composer\InstalledVersions;

use function file_get_contents;
use function hash_update;
use function implode;
use function is_dir;
use function is_file;
use function is_link;
use function is_string;
use function ksort;
use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Key\VerdictCode;
use NightWorksIO\MutationGate\Core\ThisPackage;

use function scandir;
use function sprintf;
use function stat;
use function str_starts_with;
use function time;

/**
 * The digest of the gate's own code a proof's result depends on, as Composer
 * installed it (ADR-0084): each file of the sets a runner reads, and the
 * code of each of the gate's mutator packages, by its path and its
 * contents' digest. A process keeps the digest of each runner's code where
 * it is installed, and reads its files again once one of them is added,
 * removed or written, or was written in the second it was looked at
 * (ADR-0261).
 *
 * @internal
 */
final class InstalledVerdictCode
{
    /** @var array<string, array{string, Digest}> each runner's digest where it is installed, by its files' status */
    private static array $kept = [];

    /**
     * @param Closure(string): (string|null) $installPath where a package is installed, by its name; none where it
     *                                                    is not
     * @param list<string>                  $packages    every package installed
     * @param Closure(): int                $now         the time in seconds, as the filesystem stamps a file
     */
    private function __construct(
        private readonly Closure $installPath,
        private readonly array $packages,
        private readonly Closure $now,
    ) {
    }

    /** The gate's code as Composer installed it, beside every other package it installed. */
    public static function installed(): self
    {
        return new self(InstalledVersions::getInstallPath(...), InstalledVersions::getInstalledPackages(), time(...));
    }

    /**
     * The gate's code where these packages are installed, each found by its name at the path the closure gives,
     * looked at at the time the clock gives.
     *
     * @param Closure(string): (string|null) $installPath
     * @param Closure(): int                $now
     */
    public static function at(Closure $installPath, Closure $now, string ...$packages): self
    {
        return new self($installPath, array_values($packages), $now);
    }

    /** The digest of what a run of this runner's proofs depend on. */
    public function of(string $runner): Digest
    {
        clearstatcache();
        $now = ($this->now)();
        $files = $this->filesIn(ThisPackage::COMPOSER, VerdictCode::of($runner));

        foreach ($this->plugins() as $plugin) {
            $files = [...$files, ...$this->filesIn($plugin, [VerdictCode::pluginCode()])];
        }

        ksort($files);
        $where = $this->where($runner);
        $seen = $this->seen($files, $now);
        $kept = array_key_exists($where, self::$kept) ? self::$kept[$where] : NotGiven::value();

        if (is_string($seen) && ! $kept instanceof NotGiven && $kept[0] === $seen) {
            return $kept[1];
        }

        $digest = $this->digestOf($files);

        if (is_string($seen)) {
            self::$kept[$where] = [$seen, $digest];
        }

        return $digest;
    }

    /** The runner, and where the gate and each of its mutator packages is installed. */
    private function where(string $runner): string
    {
        return implode("\0", [
            $runner,
            ...array_map(
                fn(string $package): string => (string) ($this->installPath)($package),
                [ThisPackage::COMPOSER, ...$this->plugins()],
            ),
        ]);
    }

    /**
     * What the status of each of these files says, by its name, each file
     * looked at before it is read; none where one is gone, or was written in
     * the second it was looked at or later, as a write in that second could
     * leave its status as it was.
     *
     * @param array<string, string> $files
     */
    private function seen(array $files, int $now): string|NotGiven
    {
        $seen = Digest::hashing();

        foreach ($files as $named => $file) {
            $status = stat($file);

            if ($status === false || $status['mtime'] >= $now || $status['ctime'] >= $now) {
                return NotGiven::value();
            }

            $line = sprintf(
                "%s\0%d %d %d %d %d\n",
                $named,
                $status['dev'],
                $status['ino'],
                $status['size'],
                $status['mtime'],
                $status['ctime'],
            );
            hash_update($seen, $line);
        }

        return Digest::finished($seen)->value();
    }

    /**
     * The digest of these files, each by its name and its contents' digest.
     *
     * @param array<string, string> $files
     */
    private function digestOf(array $files): Digest
    {
        $context = Digest::hashing();

        foreach ($files as $named => $file) {
            $contents = Digest::sha256Of((string) file_get_contents($file));
            hash_update($context, sprintf("%s\0%s\n", $named, $contents->value()));
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
     * path in it; none where it is not installed. A file a glob matches is
     * under the glob's base, so each file found under a base is matched
     * against that base's globs alone.
     *
     * @param  list<Glob>            $globs
     * @return array<string, string>
     */
    private function filesIn(string $package, array $globs): array
    {
        $root = ($this->installPath)($package);
        $files = [];
        $bases = [];

        if (! is_string($root)) {
            return [];
        }

        foreach ($globs as $glob) {
            $bases[$glob->base()->value()][] = $glob;
        }

        foreach ($bases as $base => $under) {
            foreach ($this->filesUnder(sprintf('%s/%s', $root, $base)) as $file) {
                $path = Path::of(mb_substr($file, mb_strlen($root) + 1));

                if (array_any($under, static fn(Glob $glob): bool => $glob->matches($path))) {
                    $files[sprintf('%s/%s', $package, $path->value())] = $file;
                }
            }
        }

        return $files;
    }

    /**
     * Each file at or under a path, a link to a file among them, and none
     * under a link to a directory.
     *
     * @return list<string>
     */
    private function filesUnder(string $at): array
    {
        if (! is_dir($at)) {
            return is_file($at) ? [$at] : [];
        }

        $files = [];

        foreach ($this->entriesOf($at) as $path) {
            array_push($files, ...(is_link($path) && is_dir($path) ? [] : $this->filesUnder($path)));
        }

        return $files;
    }

    /** @return list<string> the path of each entry of a directory */
    private function entriesOf(string $directory): array
    {
        $entries = scandir($directory);

        return array_map(
            static fn(string $entry): string => sprintf('%s/%s', $directory, $entry),
            array_values(array_diff($entries === false ? [] : $entries, ['.', '..'])),
        );
    }
}
