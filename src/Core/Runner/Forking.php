<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function function_exists;

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
}
