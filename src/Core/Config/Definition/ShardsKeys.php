<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Origin;
use NightWorksIO\MutationGate\Core\Config\Price;
use NightWorksIO\MutationGate\Core\Config\Shards;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/** How the keys a config writes are read into its `Shards` part (ADR-0002). */
final readonly class ShardsKeys
{
    /** @return list<Field<Layer>> */
    public static function fields(Origin $origin): array
    {
        $judges = Effect::JudgesOrReportsOnly;
        $seconds = Field::optional('seconds', Integer::atLeast(1), $judges);
        $max = Field::optional('max', Integer::atLeast(1), $judges);
        $target = Field::optional('target', Duration::written(), $judges);
        $setup = Field::optional('setup', Duration::written(), $judges);
        $perLine = Field::optional('secondsPerLine', NumberMap::byPrefix(Number::atLeast(0), $origin), $judges);
        $perMinute = Field::optional('perRunnerMinute', self::price(), $judges);

        return [
            Field::section(
                'shards',
                Section::of(
                    static function (Node $shards) use ($seconds, $max, $target, $setup): Layer|Invalid {
                        $cut = $seconds->read($shards);
                        $most = $max->read($shards);
                        $fit = $target->read($shards);
                        $before = $setup->read($shards);

                        return Reading::built(
                            static fn(): Layer => Layer::of(Shards::of(
                                seconds: self::secondsOf($cut->value()),
                                max: $most->value(),
                                target: $fit->value(),
                                setup: $before->value(),
                            )),
                            $cut,
                            $most,
                            $fit,
                            $before,
                        );
                    },
                    $seconds,
                    $max,
                    $target,
                    $setup,
                )->atMostOne(['seconds', 'target']),
            ),
            Field::section(
                'costs',
                Section::of(
                    static function (Node $costs) use ($perLine, $perMinute): Layer|Invalid {
                        $lines = $perLine->read($costs);
                        $minutes = $perMinute->read($costs);

                        return Reading::built(
                            static fn(): Layer => Layer::of(Shards::of(
                                secondsPerLine: $lines->value(),
                                perRunnerMinute: $minutes->value(),
                            )),
                            $lines,
                            $minutes,
                        );
                    },
                    $perLine,
                    $perMinute,
                ),
            ),
        ];
    }

    private static function secondsOf(int|Absent $seconds): Seconds|Absent
    {
        return $seconds instanceof Absent ? $seconds : Seconds::of($seconds);
    }

    /** @return Section<Price> */
    private static function price(): Section
    {
        $judges = Effect::JudgesOrReportsOnly;
        $amount = Field::required('amount', Number::atLeast(0), $judges);
        $currency = Field::required('currency', Text::of('a currency, such as EUR'), $judges);

        return Section::of(
            static function (Node $price) use ($amount, $currency): Price|Invalid {
                $much = $amount->read($price);
                $in = $currency->read($price);

                return Reading::built(static fn(): Price => Price::of($much->must(), $in->must()), $much, $in);
            },
            $amount,
            $currency,
        );
    }
}
