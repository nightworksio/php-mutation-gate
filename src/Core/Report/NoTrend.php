<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

/** A verdict off the default branch, which keeps no trend, so it has no runs before it to compare with. */
final readonly class NoTrend
{
    public static function offTheDefaultBranch(): self
    {
        return new self();
    }
}
