<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

/** A unit's result that this run produced, so no earlier run's proof names where it came from. */
final readonly class ThisRun
{
    public static function result(): self
    {
        return new self();
    }
}
