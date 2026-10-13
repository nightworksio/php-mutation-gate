<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use function file_put_contents;
use function is_string;

/**
 * The arguments Pest started a mutant's own process with, as the plugin
 * receives them before it orders the process's tests, written to the
 * process's killer file for Pest's own process to take into the results
 * file, so a replay of the run can start the same command (see
 * PrefixReplays). A process that a timeout stops while it writes them leaves
 * a line its killer file drops, never one cut short inside the results file.
 * A process the adapter names no results file or copy for records nothing.
 */
final readonly class StartedWith
{
    /** @param list<string> $arguments */
    public static function record(string|false $results, string|false $mutated, array $arguments): void
    {
        if (is_string($results) && $results !== '' && is_string($mutated) && $mutated !== '') {
            file_put_contents(
                KillerFile::beside($results, $mutated),
                KillerFile::arguments($arguments),
                FILE_APPEND | LOCK_EX,
            );
        }
    }
}
