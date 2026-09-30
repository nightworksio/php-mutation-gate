<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Time;

/** No bound on how long something may last: `ignores.maxDays` where the config sets none. */
final readonly class Unbounded
{
    private function __construct()
    {
    }

    public static function limit(): self
    {
        return new self();
    }
}
