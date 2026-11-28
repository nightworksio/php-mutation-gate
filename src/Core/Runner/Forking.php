<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function function_exists;

use Phar;

/**
 * Whether this PHP can fork a process and signal it: what a split stream
 * needs (ADR-0117).
 *
 * @internal
 */
final readonly class Forking
{
    public static function isAvailable(): bool
    {
        return function_exists('pcntl_fork') && function_exists('posix_kill');
    }

    /**
     * Whether this process can fork a child that goes on loading the gate's
     * classes beside it: not where the gate runs from its PHAR, whose open
     * archive a child shares with its parent, so that a class each loads at
     * once reads as corrupt (ADR-0224).
     */
    public static function besideTheGate(): bool
    {
        return self::isAvailable() && Phar::running(returnPhar: false) === '';
    }
}
