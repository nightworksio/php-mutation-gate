<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function is_bool;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Reports;
use NightWorksIO\MutationGate\Core\Config\Suggest;
use NightWorksIO\MutationGate\Core\Format\Node;

/**
 * How the `suggest` keys a config writes are read into its `Reports` part (ADR-0002, ADR-0076): when
 * `config:suggest` proposes a change, which reports and never runs.
 *
 * @internal
 */
final readonly class SuggestKeys
{
    private const string SHARE = 'share';

    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        $idle = Field::section('idle', self::idle());
        $ignored = Field::section('ignored', self::ignored());
        $timeouts = Field::section('timeouts', self::timeouts());
        $enabled = Field::optional('enabled', Flag::boolean(), Effect::JudgesOrReportsOnly);
        $emptyHolding = Field::optional('emptyHolding', Flag::boolean(), Effect::JudgesOrReportsOnly);

        return [Field::section(
            'suggest',
            Section::of(
                static function (Node $suggest) use (
                    $idle,
                    $ignored,
                    $timeouts,
                    $enabled,
                    $emptyHolding,
                ): Layer|Invalid {
                    $on = $enabled->read($suggest);
                    $noted = $emptyHolding->read($suggest);
                    $parts = [$idle->read($suggest), $ignored->read($suggest), $timeouts->read($suggest)];

                    return Reading::built(
                        static fn(): Layer => self::laid($on->value(), $noted->value(), ...$parts),
                        $on,
                        $noted,
                        ...$parts,
                    );
                },
                $enabled,
                $emptyHolding,
                $idle,
                $ignored,
                $timeouts,
            ),
        )];
    }

    /** @param Reading<Suggest> ...$parts */
    private static function laid(
        object|string|int|float|bool $on,
        object|string|int|float|bool $noted,
        Reading ...$parts,
    ): Layer {
        $suggest = Suggest::of(
            enabled: is_bool($on) ? $on : Absent::setting(),
            emptyHolding: is_bool($noted) ? $noted : Absent::setting(),
        );

        foreach ($parts as $part) {
            $suggest = $suggest->over($part->must());
        }

        return Layer::of(Reports::suggesting($suggest));
    }

    /** @return Section<Suggest> `suggest.idle`: `judged`, `survivors` and `share` */
    private static function idle(): Section
    {
        $reports = Effect::JudgesOrReportsOnly;
        $judged = Field::optional('judged', Integer::atLeast(1), $reports);
        $survivors = Field::optional('survivors', Integer::atLeast(0), $reports);
        $share = Field::optional(self::SHARE, Number::percent(), $reports);

        return Section::of(
            static function (Node $idle) use ($judged, $survivors, $share): Suggest|Invalid {
                $least = $judged->read($idle);
                $most = $survivors->read($idle);
                $part = $share->read($idle);

                return Reading::built(
                    static fn(): Suggest => Suggest::of(
                        idleJudged: $least->value(),
                        idleSurvivors: $most->value(),
                        idleShare: $part->value(),
                    ),
                    $least,
                    $most,
                    $part,
                );
            },
            $judged,
            $survivors,
            $share,
        );
    }

    /** @return Section<Suggest> `suggest.ignored`: `share` and `mutants` */
    private static function ignored(): Section
    {
        $reports = Effect::JudgesOrReportsOnly;
        $share = Field::optional(self::SHARE, Number::percent(), $reports);
        $mutants = Field::optional('mutants', Integer::atLeast(1), $reports);

        return Section::of(
            static function (Node $ignored) use ($share, $mutants): Suggest|Invalid {
                $part = $share->read($ignored);
                $least = $mutants->read($ignored);

                return Reading::built(
                    static fn(): Suggest => Suggest::of(ignoredShare: $part->value(), ignoredMutants: $least->value()),
                    $part,
                    $least,
                );
            },
            $share,
            $mutants,
        );
    }

    /** @return Section<Suggest> `suggest.timeouts`: `cluster` */
    private static function timeouts(): Section
    {
        $cluster = Field::optional('cluster', Integer::atLeast(1), Effect::JudgesOrReportsOnly);

        return Section::of(
            static function (Node $timeouts) use ($cluster): Suggest|Invalid {
                $least = $cluster->read($timeouts);

                return Reading::built(static fn(): Suggest => Suggest::of(timeoutCluster: $least->value()), $least);
            },
            $cluster,
        );
    }
}
