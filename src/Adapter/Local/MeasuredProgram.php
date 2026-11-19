<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Local;

use function is_resource;

use NightWorksIO\MutationGate\Core\NotGiven;

use function proc_close;
use function proc_open;

use const STDERR;
use const STDIN;

/**
 * A program run to measure the machine (ADR-0159), with both its output
 * streams on this process's error stream, so this process's own output
 * stays its own, and waited for.
 *
 * @internal
 */
final readonly class MeasuredProgram
{
    /**
     * The exit status of a program, its name then its arguments; none where it cannot start.
     *
     * @param list<string> $line
     */
    public static function exitOf(array $line): int|NotGiven
    {
        $pipes = [];
        $process = proc_open($line, [STDIN, STDERR, STDERR], $pipes);

        return is_resource($process) ? proc_close($process) : NotGiven::value();
    }
}
