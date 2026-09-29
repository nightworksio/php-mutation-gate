<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Time;

/** A duration nothing measured. */
final readonly class Unmeasured
{
    public static function duration(): self
    {
        return new self();
    }
}
