<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Score;

/** A set with no mutant in it, which has no score and passes. */
final readonly class NothingToMutate
{
    /** What a set with nothing to mutate says where a score, or a shard's label, would stand. */
    public const string SAID = 'nothing to mutate';

    public static function found(): self
    {
        return new self();
    }
}
