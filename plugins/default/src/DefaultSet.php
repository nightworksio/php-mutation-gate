<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateDefault;

use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\MutatorSet;

/** The set's name, which a config's `mutators.sets` turns it on by, and each of its mutators' names. */
final readonly class DefaultSet
{
    public static function name(): Name
    {
        return MutatorSet::defaultName();
    }

    /** A mutator of the set, by its own name. */
    public static function mutator(string $own): MutatorName
    {
        return MutatorName::of(MutatorSet::defaultName()->value(), $own);
    }
}
