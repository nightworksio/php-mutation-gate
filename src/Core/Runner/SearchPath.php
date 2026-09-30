<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function dirname;
use function sprintf;

/**
 * The `PATH` a process the gate starts gets: the running PHP's directory
 * first, so that a script's `#!` line, and every process a runner starts
 * through one, runs on the PHP that runs the gate.
 */
final readonly class SearchPath
{
    /** The variable's name. */
    public const string VARIABLE = 'PATH';

    /** The path, before what the process would inherit. */
    public static function phpFirst(string $inherited): string
    {
        return sprintf('%s%s%s', dirname(PHP_BINARY), PATH_SEPARATOR, $inherited);
    }
}
