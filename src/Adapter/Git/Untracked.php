<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Git;

/**
 * The command that lists the untracked files that are not ignored, leaving
 * out the gate's own workspace: what the gate writes, or checks out, while it
 * runs is never a change.
 */
final readonly class Untracked
{
    /** @return list<string> */
    public static function command(): array
    {
        return [
            'ls-files',
            '--others',
            '--exclude-standard',
            '-z',
            ...OutsideTheWorkspace::pathspec(),
        ];
    }
}
