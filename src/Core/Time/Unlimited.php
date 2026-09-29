<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Time;

/** No limit on how long something may take. */
final readonly class Unlimited
{
    public static function time(): self
    {
        return new self();
    }
}
