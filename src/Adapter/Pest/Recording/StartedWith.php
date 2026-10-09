<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use function file_put_contents;
use function is_string;

/**
 * The arguments Pest started a mutant's own process with, as the plugin
 * receives them before it orders the process's tests, recorded by the copy
 * the process serves, so a replay of the run can start the same command
 * (see PrefixReplays). A process the adapter names no results file or copy
 * for records nothing.
 */
final readonly class StartedWith
{
    /** @param list<string> $arguments */
    public static function record(string|false $results, string|false $mutated, array $arguments): void
    {
        if (is_string($results) && $results !== '' && is_string($mutated) && $mutated !== '') {
            file_put_contents($results, RecordLine::arguments($mutated, $arguments), FILE_APPEND | LOCK_EX);
        }
    }
}
