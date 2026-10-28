<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function preg_match;

/** A path as an ini setting's value, which PHP reads as it is only where it holds no ini syntax. */
final readonly class IniPath
{
    /**
     * What PHP reads as ini syntax in a setting's value, which it quotes: a
     * quote, `${`, and a backslash before a backslash.
     */
    private const string SYNTAX = '/"|\$\{|\\\\\\\\/';

    /** Whether PHP reads the path as it is, quoted, in an ini setting's value. */
    public static function isPlain(string $path): bool
    {
        return preg_match(self::SYNTAX, $path) !== 1;
    }
}
