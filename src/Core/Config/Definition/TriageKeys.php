<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\SurvivorsFirst;
use NightWorksIO\MutationGate\Core\Config\TestOrder;
use NightWorksIO\MutationGate\Core\Config\TimeoutMode;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/** How the keys a config writes are read into its `Triage` part (ADR-0002). */
final readonly class TriageKeys
{
    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        $judges = Effect::JudgesOrReportsOnly;
        $results = Effect::AffectsResults;
        $mode = Field::optional('mode', Enumerated::of(TimeoutMode::cases()), $judges);
        $seconds = Field::optional('seconds', Integer::atLeast(1), $results);
        $retries = Field::optional('retries', Integer::atLeast(0), $results);

        return [
            Field::optional(
                'budget',
                Into::of(
                    Duration::written(),
                    static fn(Seconds $budget): Layer => Layer::of(Triage::of(budget: $budget)),
                ),
                $judges,
            ),
            Field::section(
                'timeouts',
                Section::of(
                    static function (Node $timeouts) use ($mode, $seconds, $retries): Layer|Invalid {
                        $timedOut = $mode->read($timeouts);
                        $limit = $seconds->read($timeouts);
                        $again = $retries->read($timeouts);
                        $cap = $limit->value();

                        return Reading::built(
                            static fn(): Layer => Layer::of(Triage::of(
                                mode: $timedOut->value(),
                                limit: $cap instanceof Absent ? $cap : Seconds::of($cap),
                                retries: $again->value(),
                            )),
                            $timedOut,
                            $limit,
                            $again,
                        );
                    },
                    $mode,
                    $seconds,
                    $retries,
                ),
            ),
            Field::section(
                'flaky',
                Section::single(
                    Field::optional('confirmSurvivors', Flag::boolean(), $results),
                    static fn(bool|Absent $confirm): Layer => Layer::of(Triage::of(confirmSurvivors: $confirm)),
                ),
            ),
            Field::section(
                'tests',
                Section::single(
                    Field::optional('order', Enumerated::of(TestOrder::cases()), $results),
                    static fn(TestOrder|Absent $order): Layer => Layer::of(Triage::of(order: $order)),
                ),
            ),
            Field::section(
                'survivorsFirst',
                Section::single(
                    Field::optional('max', Integer::atLeast(0), $judges),
                    static fn(int|Absent $most): Layer => Layer::of(Triage::of(
                        survivorsFirst: $most instanceof Absent ? $most : SurvivorsFirst::atMost($most),
                    )),
                ),
            ),
        ];
    }
}
