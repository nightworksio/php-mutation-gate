<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Local;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Serve\ServeStart;

/**
 * How the keys a config writes are read into its `Local` part (ADR-0002).
 *
 * @internal
 */
final readonly class LocalKeys
{
    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        $judges = Effect::JudgesOrReportsOnly;
        $watch = Field::optional('watchBudget', Duration::written(), $judges);
        $prePush = Field::optional('prePushBudget', Duration::written(), $judges);
        $idle = Field::optional('serveIdle', Duration::written(), $judges);
        $start = Field::optional('serveStart', Enumerated::of(ServeStart::cases()), $judges);
        $startWait = Field::optional('serveStartWait', Duration::written(), $judges);
        $infer = Field::optional('inferSubsumed', Flag::boolean(), $judges);
        $poll = Field::optional('watchPoll', Duration::written(), $judges);
        $headroom = Field::optional('memoryHeadroom', Integer::atLeast(1), $judges);
        $request = Field::optional('serveRequest', Duration::written(), $judges);
        $quick = Field::optional('quick', Flag::boolean(), $judges);

        return [Field::section(
            'triage',
            Section::single(
                Field::optional('fewest', Integer::atLeast(2), $judges),
                static fn(int|Absent $fewest): Layer => Layer::of(Local::of(triageFewest: $fewest)),
            ),
        ), Field::section(
            'local',
            Section::of(
                static function (Node $local) use (
                    $watch,
                    $prePush,
                    $idle,
                    $start,
                    $startWait,
                    $infer,
                    $poll,
                    $headroom,
                    $request,
                    $quick,
                ): Layer|Invalid {
                    $working = LocalWorkKeys::read($local);
                    $watching = $watch->read($local);
                    $pushing = $prePush->read($local);
                    $idling = $idle->read($local);
                    $starting = $start->read($local);
                    $waiting = $startWait->read($local);
                    $inferring = $infer->read($local);
                    $polling = $poll->read($local);
                    $room = $headroom->read($local);
                    $requesting = $request->read($local);
                    $quickly = $quick->read($local);

                    return Reading::built(
                        static fn(): Layer => Layer::of(Local::of(
                            $watching->value(),
                            $pushing->value(),
                            $idling->value(),
                            $starting->value(),
                            $waiting->value(),
                            $inferring->value(),
                            $polling->value(),
                            memoryHeadroom: $room->value(),
                            serveRequest: $requesting->value(),
                            work: $working->value(),
                            quick: $quickly->value(),
                        )),
                        $watching,
                        $pushing,
                        $idling,
                        $starting,
                        $waiting,
                        $inferring,
                        $polling,
                        $room,
                        $requesting,
                        $working,
                        $quickly,
                    );
                },
                $watch,
                $prePush,
                $idle,
                $start,
                $startWait,
                $infer,
                $poll,
                $headroom,
                $request,
                $quick,
                ...LocalWorkKeys::fields(),
            ),
        )];
    }
}
