<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\FirstFork;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Pest;
use NightWorksIO\MutationGate\Core\Config\SlowdownCarry;
use NightWorksIO\MutationGate\Core\Config\SplitEarly;
use NightWorksIO\MutationGate\Core\Config\SplitStream;
use NightWorksIO\MutationGate\Core\Config\StreamJudging;
use NightWorksIO\MutationGate\Core\Config\StreamSharding;
use NightWorksIO\MutationGate\Core\Config\StreamWork;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Runner\Multiple;

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
        $coveringOnly = Field::optional(SplitStream::COVERING_TESTS_ONLY, Flag::boolean(), $results);
        $deadline = Field::optional(StreamWork::PARENT_DEADLINE, Flag::boolean(), Effect::DecidesHowTheGateRuns);
        $backoff = Field::optional(StreamWork::DEADLINE_BACKOFF, Number::atLeast(1), Effect::DecidesHowTheGateRuns);
        $carry = Field::optional(
            StreamWork::SLOWDOWN_CARRY,
            Enumerated::of(SlowdownCarry::cases()),
            Effect::DecidesHowTheGateRuns,
        );
        $firstFork = Field::optional(
            StreamWork::FIRST_FORK,
            Enumerated::of(FirstFork::cases()),
            Effect::DecidesHowTheGateRuns,
        );
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
                    $coveringOnly,
                    $deadline,
                    $backoff,
                    $carry,
                    $firstFork,
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
                    $selected = $coveringOnly->read($split);
                    $deadlined = $deadline->read($split);
                    $backedOff = $backoff->read($split);
                    $times = $backedOff->value();
                    $carried = $carry->read($split);
                    $forked = $firstFork->read($split);

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
                            $selected->value(),
                            new StreamWork(
                                $deadlined->value(),
                                $times instanceof Absent ? $times : Multiple::of($times),
                                $carried->value(),
                                $forked->value(),
                            ),
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
                        $selected,
                        $deadlined,
                        $backedOff,
                        $carried,
                        $forked,
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
                $coveringOnly,
                $deadline,
                $backoff,
                $carry,
                $firstFork,
            ),
        )];
    }
}
