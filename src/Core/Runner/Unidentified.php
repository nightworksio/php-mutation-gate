<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/** A run whose flows did not say which runner judged it. */
final readonly class Unidentified
{
    public static function runner(): self
    {
        return new self();
    }
}
