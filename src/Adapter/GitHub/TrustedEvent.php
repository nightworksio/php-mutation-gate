<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

/** The GitHub Actions events whose ref is a branch a run may write for: none runs code from a pull request. */
enum TrustedEvent: string
{
    case Push = 'push';
    case Schedule = 'schedule';
    case Dispatch = 'workflow_dispatch';

    /** Whether a run of this event, as `GITHUB_EVENT_NAME` names it, may write for its branch. */
    public static function names(string $event): bool
    {
        return self::tryFrom($event) instanceof self;
    }
}
