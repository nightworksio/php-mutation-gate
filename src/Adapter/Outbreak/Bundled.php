<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Outbreak;

use function array_column;
use function array_key_exists;
use function chmod;
use function file_get_contents;
use function hash_file;
use function is_executable;
use function is_file;

use NightWorksIO\MutationGate\Core\File\FileMode;
use NightWorksIO\MutationGate\Core\NotGiven;

use function php_uname;
use function preg_match_all;
use function sprintf;

/**
 * The `outbreak` binary the package ships for the machine it runs on
 * (ADR-0118): `<os>-<arch>/outbreak` under the package's `resources/outbreak`,
 * `.exe` on Windows, used only where its SHA-256 is the one `SHA256SUMS`
 * beside it lists. A machine no binary is built for, a binary missing or one
 * whose checksum differs has none, and the gate does the work itself. So with
 * `outbreak.so` (ADR-0130), under `<os>-<arch>/php<minor>/`, built for each
 * PHP minor without thread safety, listed in the same file: a PHP it is not
 * built for, Windows among them, has none, and every mutant runs.
 *
 * @internal
 */
final readonly class Bundled
{
    /** The file that lists each binary's SHA-256, as `sha256sum` writes it. */
    public const string SUMS = 'SHA256SUMS';

    /** Each binary's path under the directory, by its platform. */
    private const string BINARY = '%s/outbreak%s';

    private const string EXE = '.exe';

    /** Each extension's path under the directory, by its platform and PHP minor. */
    private const string EXTENSION = '%s/php%s/outbreak.so';

    /** A line of the list as `sha256sum` writes it: a SHA-256 in hex, two spaces, and the file's path. */
    private const string LINE = '/^([0-9a-f]{64})  (.+)$/m';

    /** The binary for this machine, in this directory, where it is there and its checksum is the one listed. */
    public static function in(string $directory): string|NotGiven
    {
        return self::for($directory, PHP_OS_FAMILY, php_uname('m'));
    }

    /** The binary for a machine of this OS family and architecture, as for `in()`. */
    public static function for(string $directory, string $family, string $machine): string|NotGiven
    {
        $platform = Platform::of($family, $machine);

        if (! $platform instanceof Platform) {
            return $platform;
        }

        $name = sprintf(self::BINARY, $platform->name(), $platform->isWindows() ? self::EXE : '');
        $listed = self::listed($directory);
        $binary = sprintf('%s/%s', $directory, $name);

        return array_key_exists($name, $listed) && is_file($binary) && self::matches($binary, $listed[$name])
            ? self::runnable($binary)
            : NotGiven::value();
    }

    /**
     * The extension for this PHP, built as the tests' PHP is, in this
     * directory, where it is there and its checksum is the one listed.
     */
    public static function extensionIn(string $directory): string|NotGiven
    {
        return PHP_ZTS
            ? NotGiven::value()
            : self::extensionFor(
                $directory,
                PHP_OS_FAMILY,
                php_uname('m'),
                sprintf('%d.%d', PHP_MAJOR_VERSION, PHP_MINOR_VERSION),
            );
    }

    /**
     * The extension for a PHP of this minor on a machine of this OS family and architecture, as for `extensionIn()`.
     */
    public static function extensionFor(
        string $directory,
        string $family,
        string $machine,
        string $minor,
    ): string|NotGiven {
        $platform = Platform::of($family, $machine);

        if (! $platform instanceof Platform || $platform->isWindows()) {
            return NotGiven::value();
        }

        $name = sprintf(self::EXTENSION, $platform->name(), $minor);
        $listed = self::listed($directory);
        $extension = sprintf('%s/%s', $directory, $name);

        return array_key_exists($name, $listed) && is_file($extension) && self::matches($extension, $listed[$name])
            ? $extension
            : NotGiven::value();
    }

    /** @return array<string, string> each listed file's SHA-256, by its path under the directory */
    private static function listed(string $directory): array
    {
        $sums = sprintf('%s/%s', $directory, self::SUMS);

        if (! is_file($sums)) {
            return [];
        }

        preg_match_all(self::LINE, sprintf('%s', file_get_contents($sums)), $lines, PREG_SET_ORDER);

        return array_column($lines, 1, 2);
    }

    /** Whether a binary's SHA-256 is the one listed; neither is a secret, so they are compared as they are. */
    private static function matches(string $binary, string $sum): bool
    {
        return hash_file('sha256', $binary) === $sum;
    }

    private static function runnable(string $binary): string|NotGiven
    {
        return is_executable($binary) || chmod($binary, FileMode::RUNNABLE) ? $binary : NotGiven::value();
    }
}
