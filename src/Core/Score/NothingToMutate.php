<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Score;

/** A set with no mutant in it, which has no score and passes. */
final readonly class NothingToMutate
{
    public static function found(): self
    {
        return new self();
    }
}
