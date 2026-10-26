<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use Symfony\Component\Process\Process;

use function trim;

/**
 * Processes a test of a warm worker watches for: one that is there for a
 * moment, under no process of the suite's, so the system reaps it as it
 * ends, and an id no process has.
 */
final readonly class Passing
{
    /** An id above every process id Linux and macOS give. */
    public const int NONE = 2_147_483_646;

    /** The id of a process that sleeps this many seconds, then ends. */
    public static function for(string $seconds): int
    {
        $started = new Process(['sh', '-c', 'sleep "$1" > /dev/null 2>&1 & echo $!', 'sh', $seconds]);
        $started->mustRun();

        return (int) trim($started->getOutput());
    }
}
