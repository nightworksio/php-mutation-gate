<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Floors;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Table;

/**
 * How the `owners` keys a config writes are read into its `Floors` part (ADR-0022).
 *
 * @internal
 */
final readonly class OwnersKeys
{
    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        return [Field::section(
            'owners',
            Section::single(
                Field::optional('floors', NumberMap::of(Number::percent()), Effect::JudgesOrReportsOnly),
                static fn(Table|Absent $floors): Layer => Layer::of(Floors::of(owners: $floors)),
            ),
        )];
    }
}
