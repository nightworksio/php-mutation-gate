<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/** A log that says nothing. */
final readonly class Unlogged implements ProcessLog
{
    public function say(string $line): void
    {
    }
}
