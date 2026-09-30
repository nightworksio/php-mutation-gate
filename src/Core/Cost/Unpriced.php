<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

/** Time shown without money, as it is until a team sets `costs.perRunnerMinute` (ADR-0016, decision 7). */
final readonly class Unpriced
{
    public static function time(): self
    {
        return new self();
    }
}
