<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

/** A change that touches no file deciding how the gate runs in its package. */
final readonly class NotDeciding
{
    public static function change(): self
    {
        return new self();
    }
}
