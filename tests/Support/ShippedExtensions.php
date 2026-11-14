<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function hash;

use NightWorksIO\MutationGate\Adapter\Outbreak\Bundled;
use NightWorksIO\MutationGate\Adapter\Outbreak\Platform;

use const PHP_MAJOR_VERSION;
use const PHP_MINOR_VERSION;
use const PHP_OS_FAMILY;

use function php_uname;
use function sprintf;

/** A directory of the package's shipped Outbreak files holding an extension for the machine and PHP the tests run on. */
final readonly class ShippedExtensions
{
    /** The extension's text, which no PHP loads. */
    public const string TEXT = 'the extension';

    /** The directory. */
    public static function directory(): string
    {
        $directory = Scratch::directory();
        Scratch::write($directory, self::relative(), self::TEXT);
        Scratch::write($directory, Bundled::SUMS, sprintf("%s  %s\n", hash('sha256', self::TEXT), self::relative()));

        return $directory;
    }

    /** The extension's path in a directory this made. */
    public static function in(string $directory): string
    {
        return sprintf('%s/%s', $directory, self::relative());
    }

    /** The extension's path under the directory, as the package names it for this machine and PHP. */
    private static function relative(): string
    {
        $platform = Platform::of(PHP_OS_FAMILY, php_uname('m'));

        return sprintf(
            '%s/php%d.%d/outbreak.so',
            $platform instanceof Platform ? $platform->name() : 'unknown',
            PHP_MAJOR_VERSION,
            PHP_MINOR_VERSION,
        );
    }
}
