<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\DigestRows;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Reports;

/**
 * How the `digest` keys a config writes are read into its `Reports` part (ADR-0002, ADR-0009): how much the
 * digest says, which reports and never judges.
 *
 * @internal
 */
final readonly class DigestKeys
{
    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        return [Field::section(
            'digest',
            Section::single(
                Field::optional('tests', Integer::atLeast(0), Effect::JudgesOrReportsOnly),
                static fn(int|Absent $tests): Layer => Layer::of(
                    Reports::digesting(DigestRows::of($tests)),
                ),
            ),
        )];
    }
}
