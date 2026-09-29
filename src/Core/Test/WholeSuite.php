<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

/** Every test of the suite, selected by nothing narrower. */
final readonly class WholeSuite
{
    public static function tests(): self
    {
        return new self();
    }
}
