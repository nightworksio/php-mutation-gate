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

/** A directory of the Outbreak files the package ships, holding them for the machine and PHP the tests run on. */
final readonly class ShippedOutbreak
{
    /** The extension's text, which no PHP loads. */
    public const string EXTENSION = 'the extension';

    /** A directory holding the extension for this machine and PHP. */
    public static function withExtension(): string
    {
        $directory = Scratch::directory();
        self::listed($directory, self::extensionName(), self::EXTENSION);

        return $directory;
    }

    /** The extension's path in a directory this made. */
    public static function extensionIn(string $directory): string
    {
        return sprintf('%s/%s', $directory, self::extensionName());
    }

    /** The binary's path under a directory the package ships, for this machine. */
    public static function binaryName(): string
    {
        return sprintf('%s/outbreak', self::platform());
    }

    /** A file written under the directory and listed with its checksum, as the package lists what it ships. */
    public static function listed(string $directory, string $name, string $text): void
    {
        Scratch::write($directory, $name, $text);
        Scratch::write($directory, Bundled::SUMS, sprintf("%s  %s\n", hash('sha256', $text), $name));
    }

    private static function extensionName(): string
    {
        return sprintf('%s/php%d.%d/outbreak.so', self::platform(), PHP_MAJOR_VERSION, PHP_MINOR_VERSION);
    }

    private static function platform(): string
    {
        $platform = Platform::of(PHP_OS_FAMILY, php_uname('m'));

        return $platform instanceof Platform ? $platform->name() : 'unknown';
    }
}
