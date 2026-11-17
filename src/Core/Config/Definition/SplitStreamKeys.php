<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Pest;
use NightWorksIO\MutationGate\Core\Config\SplitEarly;
use NightWorksIO\MutationGate\Core\Config\SplitStream;
use NightWorksIO\MutationGate\Core\Config\StreamJudging;
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
        $children = Field::optional(SplitStream::CHILDREN, new ChildCount(), $results);
        $changeCheck = Field::optional(SplitStream::CHANGE_CHECK, Flag::boolean(), $results);
        $killersFirst = Field::optional(SplitStream::KILLERS_FIRST, Flag::boolean(), $results);
        $sharding = Field::optional(SplitStream::SHARDING, Enumerated::of(StreamSharding::cases()), $results);
        $killerStreams = Field::optional(SplitStream::KILLER_STREAMS, Flag::boolean(), $results);
        $bootOnce = Field::optional(SplitStream::BOOT_ONCE, Flag::boolean(), $results);
        $reconnect = Field::optional(SplitStream::RECONNECT, Flag::boolean(), $results);
        $judging = Field::optional(SplitStream::JUDGING, Enumerated::of(StreamJudging::cases()), $results);
        $fallbackOwnRun = Field::optional(SplitStream::FALLBACK_OWN_RUN, Flag::boolean(), $results);
        $early = Field::optional(
            SplitStream::EARLY,
            Enumerated::of(SplitEarly::cases()),
            Effect::DecidesHowTheGateRuns,
        );

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
                    $early,
                    $bootOnce,
                    $reconnect,
                    $judging,
                    $fallbackOwnRun,
                ): Layer|Invalid {
                    $on = $enabled->read($split);
                    $most = $children->read($split);
                    $checked = $changeCheck->read($split);
                    $first = $killersFirst->read($split);
                    $cut = $sharding->read($split);
                    $streamed = $killerStreams->read($split);
                    $starts = $early->read($split);
                    $booted = $bootOnce->read($split);
                    $reconnected = $reconnect->read($split);
                    $judged = $judging->read($split);
                    $ownRun = $fallbackOwnRun->read($split);

                    return Reading::built(
                        static fn(): Layer => Layer::of(Pest::splitting(SplitStream::of(
                            $on->value(),
                            $most->value(),
                            $checked->value(),
                            $first->value(),
                            $cut->value(),
                            $streamed->value(),
                            $starts->value(),
                            $booted->value(),
                            $reconnected->value(),
                            $judged->value(),
                            $ownRun->value(),
                        ))),
                        $on,
                        $most,
                        $checked,
                        $first,
                        $cut,
                        $streamed,
                        $starts,
                        $booted,
                        $reconnected,
                        $judged,
                        $ownRun,
                    );
                },
                $enabled,
                $children,
                $changeCheck,
                $killersFirst,
                $sharding,
                $killerStreams,
                $early,
                $bootOnce,
                $reconnect,
                $judging,
                $fallbackOwnRun,
            ),
        )];
    }
}
