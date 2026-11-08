<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Outbreak;
use NightWorksIO\MutationGate\Core\Config\Shards;
use NightWorksIO\MutationGate\Core\Format\Node;

/**
 * How the `outbreak` keys a config writes are read into its `Shards` part
 * (ADR-0118). The binary's output is the gate's own, so changing any of them
 * re-runs nothing, as a key that judges or reports only does.
 *
 * @internal
 */
final readonly class OutbreakKeys
{
    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        $none = Effect::JudgesOrReportsOnly;
        $enabled = Field::optional(Outbreak::ENABLED, Flag::boolean(), $none);
        $binary = Field::optional(Outbreak::BINARY, Text::of('a path to the outbreak binary'), $none);
        $timeout = Field::optional(Outbreak::TIMEOUT, Duration::written(), $none);
        $threads = Field::optional(Outbreak::THREADS, Integer::atLeast(1), $none);

        return [Field::section(
            Outbreak::OPTION,
            Section::of(
                static function (Node $outbreak) use ($enabled, $binary, $timeout, $threads): Layer|Invalid {
                    $on = $enabled->read($outbreak);
                    $at = $binary->read($outbreak);
                    $within = $timeout->read($outbreak);
                    $cores = $threads->read($outbreak);

                    return Reading::built(
                        static fn(): Layer => Layer::of(Shards::of(outbreak: Outbreak::of(
                            $on->value(),
                            $at->value(),
                            $within->value(),
                            $cores->value(),
                        ))),
                        $on,
                        $at,
                        $within,
                        $cores,
                    );
                },
                $enabled,
                $binary,
                $timeout,
                $threads,
            ),
        )];
    }
}
