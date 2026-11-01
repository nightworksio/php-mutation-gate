<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/**
 * A process no one watches while it runs.
 *
 * @internal
 */
final readonly class Unwatched implements ProcessWatch
{
    public function look(): void
    {
    }

    public function progress(): string
    {
        return '';
    }
}
