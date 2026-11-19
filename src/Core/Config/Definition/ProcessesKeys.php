<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Processes;
use NightWorksIO\MutationGate\Core\Config\ProcessHold;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Score\Percentage;

/**
 * How the keys a config writes are read into its `Processes` part (ADR-0158).
 *
 * @internal
 */
final readonly class ProcessesKeys
{
    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        $runs = Effect::DecidesHowTheGateRuns;
        $group = Field::optional('group', Enumerated::of(ProcessHold::cases()), Effect::JudgesOrReportsOnly);
        $contain = Field::optional('contain', Enumerated::of(ProcessHold::cases()), $runs);
        $memory = Field::optional('memory', Integer::between(1, Percentage::MOST), $runs);
        $pids = Field::optional('pids', Integer::atLeast(1), $runs);

        return [
            Field::section(
                'processes',
                Section::of(
                    static function (Node $processes) use ($group, $contain, $memory, $pids): Layer|Invalid {
                        $led = $group->read($processes);
                        $held = $contain->read($processes);
                        $share = $memory->read($processes);
                        $most = $pids->read($processes);

                        return Reading::built(
                            static fn(): Layer => Layer::of(Processes::of(
                                $led->value(),
                                $held->value(),
                                $share->value(),
                                $most->value(),
                            )),
                            $led,
                            $held,
                            $share,
                            $most,
                        );
                    },
                    $group,
                    $contain,
                    $memory,
                    $pids,
                ),
            ),
        ];
    }
}
