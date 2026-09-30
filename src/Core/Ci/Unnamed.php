<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

/** A commit the CI does not name for its run. */
final readonly class Unnamed
{
    public static function commit(): self
    {
        return new self();
    }
}
