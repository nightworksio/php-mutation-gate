<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

/** No pull request's ledger to read, so no pull request's verdict is on record as passed. */
final readonly class NoLedgers
{
    public static function none(): self
    {
        return new self();
    }
}
