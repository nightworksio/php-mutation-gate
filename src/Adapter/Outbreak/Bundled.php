<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Outbreak;

use function array_key_exists;
use function chmod;
use function explode;
use function file_get_contents;
use function hash_equals;
use function hash_file;
use function is_executable;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\File\FileMode;
use NightWorksIO\MutationGate\Core\NotGiven;

use function php_uname;
use function sprintf;
use function trim;

/**
 * The `outbreak` binary the package ships for the machine it runs on
 * (ADR-0118): `<os>-<arch>/outbreak` under the package's `resources/outbreak`,
 * `.exe` on Windows, used only where its SHA-256 is the one `SHA256SUMS`
 * beside it lists. A machine no binary is built for, a binary missing or one
 * whose checksum differs has none, and the gate does the work itself.
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

    /** What separates a checksum from its file in a line of the list. */
    private const string BETWEEN = '  ';

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

    /** @return array<string, string> each listed file's SHA-256, by its path under the directory */
    private static function listed(string $directory): array
    {
        $sums = sprintf('%s/%s', $directory, self::SUMS);
        $text = is_file($sums) ? file_get_contents($sums) : false;
        $listed = [];

        foreach (is_string($text) ? explode("\n", trim($text)) : [] as $line) {
            [$sum, $file] = [...explode(self::BETWEEN, trim($line), 2), ''];
            $listed += $file === '' ? [] : [$file => $sum];
        }

        return $listed;
    }

    private static function matches(string $binary, string $sum): bool
    {
        $actual = hash_file('sha256', $binary);

        return is_string($actual) && hash_equals($sum, $actual);
    }

    private static function runnable(string $binary): string|NotGiven
    {
        return is_executable($binary) || chmod($binary, FileMode::RUNNABLE) ? $binary : NotGiven::value();
    }
}
