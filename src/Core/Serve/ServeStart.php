<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Serve;

/**
 * `local.serveStart`: whether `watch` starts a daemon (`mutation-gate
 * serve`) where none runs for the project. pre-commit and pre-push only use
 * a daemon that already runs.
 */
enum ServeStart: string
{
    /** `watch` starts a daemon where none runs, and runs in it. */
    case Watch = 'watch';

    /** No command starts one; each uses a daemon only where one already runs. */
    case Never = 'never';

    /** Whether `watch` starts a daemon where none runs. */
    public function startsForWatch(): bool
    {
        return $this === self::Watch;
    }
}
