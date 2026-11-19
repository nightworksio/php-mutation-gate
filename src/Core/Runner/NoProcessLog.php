<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/**
 * A log that says nothing, for a flow run with no log to say it on.
 *
 * @internal
 */
final readonly class NoProcessLog implements ProcessLog
{
    public function say(string $line): void
    {
    }
}
