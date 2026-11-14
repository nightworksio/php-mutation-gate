<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Process;

use function is_resource;

use NightWorksIO\MutationGate\Core\NotGiven;

use function proc_close;
use function proc_open;

use const STDERR;
use const STDIN;
use const STDOUT;

/**
 * A program run on this process's own standard streams, as a command that
 * hands the terminal over runs it, and waited for.
 *
 * @internal
 */
final readonly class Attached
{
    /**
     * The exit status of a program, its name then its arguments; none where it cannot start.
     *
     * @param list<string> $line
     */
    public static function exitOf(array $line): int|NotGiven
    {
        $pipes = [];
        $process = proc_open($line, [STDIN, STDOUT, STDERR], $pipes);

        return is_resource($process) ? proc_close($process) : NotGiven::value();
    }
}
