<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Reach;
use NightWorksIO\MutationGate\Core\Config\Sampling;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Sample\Confidence;

/**
 * How the `sampling` keys a config writes are read into its `Reach` part (ADR-0002, ADR-0020).
 *
 * @internal
 */
final readonly class SamplingKeys
{
    /** The widest half-width in points a tree's interval may be sized for. */
    private const int WIDEST = 10;

    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        $judges = Effect::JudgesOrReportsOnly;
        $confidence = Field::optional('confidence', Enumerated::of(Confidence::cases()), $judges);
        $margin = Field::optional('margin', Number::aboveAndAtMost(0, self::WIDEST), $judges);
        $minUnits = Field::optional('minUnits', Integer::atLeast(1), $judges);
        $seed = Field::optional('seed', Text::of('a seed, any text'), $judges);

        return [Field::section(
            'sampling',
            Section::of(
                static function (Node $sampling) use ($confidence, $margin, $minUnits, $seed): Layer|Invalid {
                    $sure = $confidence->read($sampling);
                    $wide = $margin->read($sampling);
                    $least = $minUnits->read($sampling);
                    $drawn = $seed->read($sampling);

                    return Reading::built(
                        static fn(): Layer => Layer::of(Reach::of(sampling: Sampling::of(
                            $sure->value(),
                            $wide->value(),
                            $least->value(),
                            $drawn->value(),
                        ))),
                        $sure,
                        $wide,
                        $least,
                        $drawn,
                    );
                },
                $confidence,
                $margin,
                $minUnits,
                $seed,
            ),
        )];
    }
}
