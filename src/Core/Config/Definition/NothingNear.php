<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

/** What a misspelt key is nearest to when no known key is close enough to suggest. */
final readonly class NothingNear
{
    private function __construct()
    {
    }

    public static function of(): self
    {
        return new self();
    }
}
