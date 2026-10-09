<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Pruning;
use NightWorksIO\MutationGate\Core\Config\Reach;
use NightWorksIO\MutationGate\Core\Format\Node;

/** How the `pruning` keys a config writes are read into its `Reach` part (ADR-0002, ADR-0025). */
final readonly class PruningKeys
{
    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        $enabled = Field::optional('enabled', Flag::boolean(), Effect::AffectsResults);
        $window = Field::optional('window', Integer::atLeast(1), Effect::AffectsResults);
        $audit = Field::optional('audit', Duration::written(), Effect::JudgesOrReportsOnly);

        return [Field::section(
            'pruning',
            Section::of(
                static function (Node $pruning) use ($enabled, $window, $audit): Layer|Invalid {
                    $on = $enabled->read($pruning);
                    $wide = $window->read($pruning);
                    $every = $audit->read($pruning);

                    return Reading::built(
                        static fn(): Layer => Layer::of(
                            Reach::of(pruning: Pruning::of($on->value(), $wide->value(), $every->value())),
                        ),
                        $on,
                        $wide,
                        $every,
                    );
                },
                $enabled,
                $window,
                $audit,
            ),
        )];
    }
}
