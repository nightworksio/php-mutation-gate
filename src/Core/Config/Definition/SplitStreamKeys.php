<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Pest;
use NightWorksIO\MutationGate\Core\Config\SplitStream;
use NightWorksIO\MutationGate\Core\Config\StreamSharding;
use NightWorksIO\MutationGate\Core\Format\Node;

/**
 * How the `splitStream` keys a config writes are read into its `Pest` part
 * (ADR-0002, ADR-0117).
 *
 * @internal
 */
final readonly class SplitStreamKeys
{
    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        $results = Effect::AffectsResults;
        $enabled = Field::optional(SplitStream::ENABLED, Flag::boolean(), $results);
        $children = Field::optional(SplitStream::CHILDREN, Integer::atLeast(1), $results);
        $changeCheck = Field::optional(SplitStream::CHANGE_CHECK, Flag::boolean(), $results);
        $killersFirst = Field::optional(SplitStream::KILLERS_FIRST, Flag::boolean(), $results);
        $sharding = Field::optional(SplitStream::SHARDING, Enumerated::of(StreamSharding::cases()), $results);
        $killerStreams = Field::optional(SplitStream::KILLER_STREAMS, Flag::boolean(), $results);

        return [Field::section(
            SplitStream::SECTION,
            Section::of(
                static function (Node $split) use (
                    $enabled,
                    $children,
                    $changeCheck,
                    $killersFirst,
                    $sharding,
                    $killerStreams,
                ): Layer|Invalid {
                    $on = $enabled->read($split);
                    $most = $children->read($split);
                    $checked = $changeCheck->read($split);
                    $first = $killersFirst->read($split);
                    $cut = $sharding->read($split);
                    $streamed = $killerStreams->read($split);

                    return Reading::built(
                        static fn(): Layer => Layer::of(Pest::splitting(SplitStream::of(
                            $on->value(),
                            $most->value(),
                            $checked->value(),
                            $first->value(),
                            $cut->value(),
                            $streamed->value(),
                        ))),
                        $on,
                        $most,
                        $checked,
                        $first,
                        $cut,
                        $streamed,
                    );
                },
                $enabled,
                $children,
                $changeCheck,
                $killersFirst,
                $sharding,
                $killerStreams,
            ),
        )];
    }
}
