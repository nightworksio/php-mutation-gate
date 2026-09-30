<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function json_encode;

use NightWorksIO\MutationGate\Core\Runner\Platform;

use function sprintf;

/** What a PHP prints describing itself, and the platform it describes. */
final class Described
{
    private const array EXTENSIONS = ['Core' => '8.5.0', 'pcov' => '1.0.12'];

    private const array INI = ['memory_limit' => '128M', 'precision' => '14', 'display_errors' => 'STDOUT'];

    private const string PHP = '8.5.0';

    private const string SYSTEM = 'Linux';

    private const string ARCHITECTURE = 'x86_64';

    /**
     * A warning PHP printed first, then its description.
     *
     * @param array<string, string> $extensions
     * @param array<string, string> $ini
     */
    public static function output(array $extensions = self::EXTENSIONS, array $ini = self::INI): string
    {
        return self::printed([
            'php' => self::PHP,
            'extensions' => $extensions,
            'ini' => $ini,
            'system' => self::SYSTEM,
            'architecture' => self::ARCHITECTURE,
        ]);
    }

    /**
     * The same, for a PHP that loads a php.ini.
     *
     * @param array<string, string> $extensions
     * @param array<string, string> $ini
     */
    public static function loading(string $iniFile, array $extensions = self::EXTENSIONS, array $ini = self::INI): string
    {
        return self::printed([
            'php' => self::PHP,
            'extensions' => $extensions,
            'ini' => $ini,
            'system' => self::SYSTEM,
            'architecture' => self::ARCHITECTURE,
            'iniFile' => $iniFile,
        ]);
    }

    public static function platform(): Platform
    {
        return Platform::of(self::PHP, self::EXTENSIONS, self::INI, self::SYSTEM, self::ARCHITECTURE);
    }

    /** @param array<string, array<string, string>|string> $description */
    private static function printed(array $description): string
    {
        return sprintf(
            "PHP Warning:  Module \"pcov\" is already loaded\nmutation-gate platform %s\n",
            json_encode($description),
        );
    }
}
