<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Alert;

/** The default branch's state did not change, so there is nothing to alert. */
final readonly class Steady
{
    public static function state(): self
    {
        return new self();
    }
}
