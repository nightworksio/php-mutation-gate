<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateSecurity;

use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Mutator\MutatorName;

/** The set's name, which a config's `mutators.sets` turns it on by, and each of its mutators' names. */
final readonly class SecuritySet
{
    private const string NAME = 'security';

    public static function name(): Name
    {
        return Name::of(self::NAME);
    }

    /** A mutator of the set, by its own name. */
    public static function mutator(string $own): MutatorName
    {
        return MutatorName::of(self::NAME, $own);
    }
}
