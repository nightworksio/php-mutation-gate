<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

use function explode;

use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;

use function preg_match;
use function sprintf;
use function version_compare;

/**
 * The PHP version a CI definition sets up (ADR-0015 decision 16): the
 * project's `config.platform.php` where it sets one, else the lowest version
 * `require.php` allows, and never below the lowest the gate runs on.
 */
final readonly class PhpVersion
{
    /** The lowest PHP version the gate runs on. */
    public const string LOWEST = '8.5';

    /** A version's major and minor. */
    private const string MINOR = '/(\d+)\.(\d+)/';

    /** A version's major alone. */
    private const string MAJOR = '/\d+/';

    /** The version, `major.minor`, the project's `composer.json` asks for. */
    public static function in(Node $manifest): string
    {
        $platform = Lenient::text($manifest->field('config')->field('platform')->field('php'));
        $required = Lenient::text($manifest->field('require')->field('php'));
        $version = $platform === '' ? self::lowestAllowed($required) : self::minorOf($platform);

        return $version !== '' && version_compare($version, self::LOWEST, '>') ? $version : self::LOWEST;
    }

    /** The lowest version of any alternative of a constraint; nothing where none names one. */
    private static function lowestAllowed(string $constraint): string
    {
        $lowest = '';

        foreach (explode('|', $constraint) as $alternative) {
            $version = self::minorOf($alternative);
            $lower = $lowest === '' || version_compare($version, $lowest, '<');
            $lowest = $version !== '' && $lower ? $version : $lowest;
        }

        return $lowest;
    }

    /** The first version a text names, as `major.minor`; nothing where it names none. */
    private static function minorOf(string $text): string
    {
        return match (true) {
            preg_match(self::MINOR, $text, $minor) === 1 => sprintf('%s.%s', $minor[1], $minor[2]),
            preg_match(self::MAJOR, $text, $major) === 1 => sprintf('%s.0', $major[0]),
            default => '',
        };
    }
}
