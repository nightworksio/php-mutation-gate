<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Duplicates;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Mutants;

/**
 * How the `mutants` keys a config writes are read into its `Mutants` part (ADR-0137).
 *
 * @internal
 */
final readonly class MutantsKeys
{
    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        return [Field::section(
            'mutants',
            Section::single(
                Field::optional(Mutants::DUPLICATES, Enumerated::of(Duplicates::cases()), Effect::AffectsResults),
                static fn(Duplicates|Absent $duplicates): Layer => Layer::of(Mutants::of($duplicates)),
            ),
        )];
    }
}
