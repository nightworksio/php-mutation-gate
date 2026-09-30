<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core;

/** A value nobody gave: an option left off the command line, or a setting left out of the config. */
final readonly class NotGiven
{
    private function __construct()
    {
    }

    public static function value(): self
    {
        return new self();
    }
}
