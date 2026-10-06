<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Proofs;
use NightWorksIO\MutationGate\Core\Format\Node;

/**
 * The `coverage` section (ADR-0023, decision 1), which the proofs part holds,
 * since the map it measures is kept beside the ledger. `incremental` is not
 * in a proof's key: an entry is reused only under its own key, so it never
 * changes what a key reads. It decides how the gate runs, since the map it
 * keeps tells what a changed test reaches (ADR-0005, decision 4).
 */
final readonly class CoverageKeys
{
    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        $incremental = Field::optional('incremental', Flag::boolean(), Effect::DecidesHowTheGateRuns);

        return [Field::section(
            'coverage',
            Section::of(
                static function (Node $coverage) use ($incremental): Layer|Invalid {
                    $measured = $incremental->read($coverage);

                    return Reading::built(
                        static fn(): Layer => Layer::of(Proofs::of(incremental: $measured->value())),
                        $measured,
                    );
                },
                $incremental,
            ),
        )];
    }
}
