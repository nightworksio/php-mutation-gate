<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Badge;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Table;

/** How the keys a config writes are read into its `Badge` part (ADR-0002). */
final readonly class BadgeKeys
{
    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        return [Field::section(
            'badge',
            Section::single(
                Field::optional('colors', NumberMap::of(Number::percent()), Effect::JudgesOrReportsOnly),
                static fn(Table|Absent $colors): Layer => Layer::of(Badge::of($colors)),
            ),
        )];
    }
}
