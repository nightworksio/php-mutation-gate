<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Time;

/** The day something never comes to: an ignore without `expires`, which never lapses. */
final readonly class Forever
{
    private function __construct()
    {
    }

    public static function of(): self
    {
        return new self();
    }
}
