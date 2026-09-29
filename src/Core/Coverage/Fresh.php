<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

/** Coverage the run collects itself, rather than reading a map another job wrote. */
final readonly class Fresh
{
    public static function coverage(): self
    {
        return new self();
    }
}
