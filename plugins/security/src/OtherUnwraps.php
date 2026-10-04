<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateSecurity;

use NightWorksIO\MutationGate\Core\Mutant\NamedMutators;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\MutatorSet;

use function sprintf;

/**
 * The other mutators that unwrap an escape function as one of the set's
 * does, by the own name each shares with it: the `default` set's, and Pest's
 * own, as the gate names their mutants.
 */
final readonly class OtherUnwraps
{
    /** Pest's own unwrap of a string function, by its class, as Pest names its mutants. */
    private const string PEST = 'Pest\\Mutate\\Mutators\\String\\%s';

    public static function named(string $own): NamedMutators
    {
        return NamedMutators::of(
            MutatorName::of(MutatorSet::defaultName()->value(), $own)->value(),
            sprintf(self::PEST, $own),
        );
    }
}
