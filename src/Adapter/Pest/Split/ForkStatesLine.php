<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function file_put_contents;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;

/**
 * The line that tells the gate how many forks of a run's streams read their
 * state in parent and child, and how many of those found it alike (H5).
 *
 * @internal
 */
final readonly class ForkStatesLine
{
    /**
     * The line written to the run's results the gate names, where the
     * streams counted any fork and it names a file.
     */
    public static function writtenFor(StreamVerdicts $verdicts, string|false $results): void
    {
        [$forks, $alike] = $verdicts->forkStates();

        if ($forks > 0 && is_string($results) && $results !== '') {
            file_put_contents($results, RecordLine::forkStates($forks, $alike), FILE_APPEND | LOCK_EX);
        }
    }
}
