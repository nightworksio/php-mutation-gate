<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\SurvivorsFirst;
use NightWorksIO\MutationGate\Core\Config\TestOrder;
use NightWorksIO\MutationGate\Core\Config\TimeoutMode;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Mutant\MutatorNamePattern;
use NightWorksIO\MutationGate\Core\Runner\TighterSilence;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/** How the keys a config writes are read into its `Triage` part (ADR-0002). */
final readonly class TriageKeys
{
    private const string SHORT = "a mutator's short name, the last part of the name a runner gives it";

    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        $judges = Effect::JudgesOrReportsOnly;
        $results = Effect::AffectsResults;
        $mode = Field::optional('mode', Enumerated::of(TimeoutMode::cases()), $judges);
        $seconds = Field::optional('seconds', Integer::atLeast(1), $results);
        $atMost = Field::optional('most', Integer::atLeast(1), $results);
        $tighter = Field::section('tighter', self::tighter($results));

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
                    static function (Node $timeouts) use ($mode, $seconds, $atMost, $tighter): Layer|Invalid {
                        $timedOut = $mode->read($timeouts);
                        $limit = $seconds->read($timeouts);
                        $upper = $atMost->read($timeouts);
                        $lower = $tighter->read($timeouts);

                        return Reading::built(
                            static fn(): Layer => Layer::of(Triage::of(
                                mode: $timedOut->value(),
                                limit: self::seconds($limit->value()),
                                most: self::seconds($upper->value()),
                                tighter: $lower->value(),
                            )),
                            $timedOut,
                            $limit,
                            $upper,
                            $lower,
                        );
                    },
                    $mode,
                    $seconds,
                    $atMost,
                    $tighter,
                ),
            ),
            Field::section(
                'flaky',
                Section::single(
                    Field::optional('confirmSurvivors', Flag::boolean(), $results),
                    static fn(bool|Absent $confirm): Layer => Layer::of(Triage::of(confirmSurvivors: $confirm)),
                ),
            ),
            Field::section('tests', self::tests($results)),
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

    /**
     * `tests`: the order each mutant's covering tests run in, and the suites
     * whose tests judge, each once and at least one where the key is written.
     *
     * @return Section<Layer>
     */
    private static function tests(Effect $results): Section
    {
        $order = Field::optional('order', Enumerated::of(TestOrder::cases()), $results);
        $suites = Field::optional(
            'suites',
            Items::distinctAtLeastOne(Text::of('a test suite\'s name'), static fn(string $name): string => $name),
            $results,
        );

        return Section::of(
            static function (Node $tests) use ($order, $suites): Layer|Invalid {
                $ordered = $order->read($tests);
                $listed = $suites->read($tests);

                return Reading::built(
                    static fn(): Layer => Layer::of(Triage::of(order: $ordered->value(), suites: $listed->value())),
                    $ordered,
                    $listed,
                );
            },
            $order,
            $suites,
        );
    }

    /**
     * `timeouts.tighter`: the mutators, each by its short name, and the floor
     * of their silence limit, each the standard where it is left out; nothing
     * where the config writes neither.
     *
     * @return Section<TighterSilence|Absent>
     */
    private static function tighter(Effect $results): Section
    {
        $named = static fn(string $name): string => $name;
        $mutators = Field::optional(
            'mutators',
            Items::distinct(Text::matching(self::SHORT, sprintf('^%s$', MutatorNamePattern::SHORT)), $named),
            $results,
        );
        $floor = Field::optional('floor', Integer::atLeast(1), $results);

        return Section::of(
            static function (Node $tighter) use ($mutators, $floor): TighterSilence|Absent|Invalid {
                $listed = $mutators->read($tighter);
                $least = $floor->read($tighter);

                return Reading::built(
                    static fn(): TighterSilence|Absent => self::tighterOf($listed->value(), $least->value()),
                    $listed,
                    $least,
                );
            },
            $mutators,
            $floor,
        );
    }

    /** @param Listed<string>|Absent $mutators */
    private static function tighterOf(Listed|Absent $mutators, int|Absent $floor): TighterSilence|Absent
    {
        $standard = TighterSilence::standard();

        return $mutators instanceof Absent && $floor instanceof Absent ? Absent::setting() : TighterSilence::of(
            $floor instanceof Absent ? $standard->floor() : Seconds::of($floor),
            ...$mutators instanceof Absent ? [...$standard] : [...$mutators],
        );
    }

    private static function seconds(int|Absent $seconds): Seconds|Absent
    {
        return $seconds instanceof Absent ? $seconds : Seconds::of($seconds);
    }
}
