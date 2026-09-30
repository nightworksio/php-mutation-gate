<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core;

/**
 * A value nobody gave: an option left off the command line, or a setting left
 * out of the config. Its constructor is public so a builder's parameter can
 * default to `new NotGiven()`.
 */
final readonly class NotGiven
{
    public static function value(): self
    {
        return new self();
    }
}
