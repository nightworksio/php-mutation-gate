<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function array_intersect;
use function array_values;
use function is_int;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Learned;
use NightWorksIO\MutationGate\Core\Config\LearnedBounds;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\SuiteLists;
use NightWorksIO\MutationGate\Core\Config\SurvivorsFirst;
use NightWorksIO\MutationGate\Core\Config\TestOrder;
use NightWorksIO\MutationGate\Core\Config\TimeoutHeadroom;
use NightWorksIO\MutationGate\Core\Config\TimeoutMode;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Mutant\MutatorNamePattern;
use NightWorksIO\MutationGate\Core\Runner\TighterSilence;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * How the keys a config writes are read into its `Triage` part (ADR-0002).
 *
 * @internal
 */
final readonly class TriageKeys
{
    private const string SHORT = "a mutator's short name, the last part of the name a runner gives it";

    private const string IN_BOTH
        = '%s is in tests.suites too: a suite\'s tests judge every unit, or only the units they hold';

    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        $judges = Effect::JudgesOrReportsOnly;
        $results = Effect::AffectsResults;
        $mode = Field::optional('mode', Enumerated::of(TimeoutMode::cases()), $judges);
        $headroom = Field::optional('headroom', Number::between(0, TimeoutHeadroom::MOST), $judges);
        $seconds = Field::optional('seconds', OrLearned::of(Integer::atLeast(1)), $results);
        $atMost = Field::optional('most', Integer::atLeast(1), $results);
        $multiple = Field::optional('multiple', OrLearned::of(Number::atLeast(1)), $results);
        $tighter = Field::section('tighter', self::tighter($results));
        $perTest = Field::optional('perTest', MultipleOrOff::atLeast(1), $results);
        $testFloor = Field::optional('testFloor', Integer::atLeast(1), $results);
        $controlMost = Field::optional('controlMost', Integer::atLeast(1), $results);
        $startUp = Field::optional('startUp', Duration::written(), $results);
        $startUpMultiple = Field::optional('startUpMultiple', Number::atLeast(1), $results);
        $learned = Field::section('learned', self::learned());
        $heavyFill = Field::optional('heavyFill', Flag::boolean(), $judges);
        $heavyParts = Field::optional('heavyParts', Integer::atLeast(1), $results);
        $toggles = ToggleFields::in('timeouts');

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
                    static function (Node $timeouts) use (
                        $mode,
                        $headroom,
                        $seconds,
                        $atMost,
                        $multiple,
                        $tighter,
                        $perTest,
                        $testFloor,
                        $controlMost,
                        $startUp,
                        $startUpMultiple,
                        $learned,
                        $heavyFill,
                        $heavyParts,
                        $toggles,
                    ): Layer|Invalid {
                        $switched = $toggles->read($timeouts);
                        $timedOut = $mode->read($timeouts);
                        $unused = $headroom->read($timeouts);
                        $limit = $seconds->read($timeouts);
                        $upper = $atMost->read($timeouts);
                        $times = $multiple->read($timeouts);
                        $lower = $tighter->read($timeouts);
                        $eachTest = $perTest->read($timeouts);
                        $least = $testFloor->read($timeouts);
                        $controls = $controlMost->read($timeouts);
                        $starts = $startUp->read($timeouts);
                        $startTimes = $startUpMultiple->read($timeouts);
                        $bounds = $learned->read($timeouts);
                        $fills = $heavyFill->read($timeouts);
                        $cap = $heavyParts->read($timeouts);

                        return Reading::built(
                            static fn(): Layer => Layer::of(Triage::of(
                                mode: $timedOut->value(),
                                limit: self::seconds($limit->value()),
                                most: self::seconds($upper->value()),
                                tighter: $lower->value(),
                                multiple: $times->value(),
                                perTest: $eachTest->value(),
                                testFloor: self::seconds($least->value()),
                                controlMost: self::seconds($controls->value()),
                                startUp: $starts->value(),
                                startUpMultiple: $startTimes->value(),
                                learned: $bounds->value(),
                                heavyFill: $fills->value(),
                                heavyParts: $cap->value(),
                                headroom: $unused->value(),
                            ), $toggles->set($switched)),
                            $timedOut,
                            $unused,
                            $limit,
                            $upper,
                            $times,
                            $lower,
                            $eachTest,
                            $least,
                            $controls,
                            $starts,
                            $startTimes,
                            $bounds,
                            $fills,
                            $cap,
                            ...$switched,
                        );
                    },
                    $mode,
                    $headroom,
                    $seconds,
                    $atMost,
                    $multiple,
                    $tighter,
                    $perTest,
                    $testFloor,
                    $controlMost,
                    $startUp,
                    $startUpMultiple,
                    $learned,
                    $heavyFill,
                    $heavyParts,
                    ...$toggles->fields(),
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
            Field::section('survivorsFirst', self::survivorsFirst($judges)),
        ];
    }

    /** @return Section<LearnedBounds> */
    private static function learned(): Section
    {
        $judges = Effect::AffectsResults;
        $percentile = Field::optional('percentile', Number::between(0, 1), $judges);
        $least = Field::optional('least', Number::atLeast(1), $judges);
        $most = Field::optional('most', Number::atLeast(1), $judges);

        return Section::of(
            static function (Node $learned) use ($percentile, $least, $most): LearnedBounds|Invalid {
                $share = $percentile->read($learned);
                $lower = $least->read($learned);
                $upper = $most->read($learned);

                return Reading::built(
                    static fn(): LearnedBounds => LearnedBounds::of($share->value(), $lower->value(), $upper->value()),
                    $share,
                    $lower,
                    $upper,
                );
            },
            $percentile,
            $least,
            $most,
        );
    }

    /**
     * `survivorsFirst`: how many of the last run's survivors a pull request's
     * run re-checks first, within what time, across how many jobs (ADR-0106).
     *
     * @return Section<Layer>
     */
    private static function survivorsFirst(Effect $judges): Section
    {
        $max = Field::optional('max', Integer::atLeast(0), $judges);
        $budget = Field::optional('budget', Duration::written(), $judges);
        $shards = Field::optional('shards', Integer::between(1, SurvivorsFirst::MOST_SHARDS), $judges);

        return Section::of(
            static function (Node $first) use ($max, $budget, $shards): Layer|Invalid {
                $most = $max->read($first);
                $within = $budget->read($first);
                $across = $shards->read($first);

                return Reading::built(
                    static fn(): Layer => Layer::of(Triage::of(
                        survivorsFirst: SurvivorsFirst::of($most->value(), $within->value(), $across->value()),
                    )),
                    $most,
                    $within,
                    $across,
                );
            },
            $max,
            $budget,
            $shards,
        );
    }

    /**
     * `tests`: the order each mutant's covering tests run in, the suites
     * whose tests judge every unit, and those whose tests judge only the
     * units they hold, each list once a name, `suites` at least one where it
     * is written and `holding` none or more (ADR-0116), and no suite in both
     * (ADR-0002).
     *
     * @return Section<Layer>
     */
    private static function tests(Effect $results): Section
    {
        $order = Field::optional('order', Enumerated::of(TestOrder::cases()), $results);
        $name = Text::of('a test suite\'s name');
        $identity = static fn(string $named): string => $named;
        $suites = Field::optional('suites', Items::distinctAtLeastOne($name, $identity), $results);
        $holding = Field::optional('holding', Items::distinct($name, $identity), $results);

        return Section::of(
            static function (Node $tests) use ($order, $suites, $holding): Layer|Invalid {
                $ordered = $order->read($tests);
                $judging = $suites->read($tests);
                $held = $holding->read($tests);
                $both = self::inBoth($judging->value(), $held->value());

                return $both instanceof Invalid ? $both : Reading::built(
                    static fn(): Layer => Layer::of(Triage::of(
                        order: $ordered->value(),
                        lists: SuiteLists::of($judging->value(), $held->value()),
                    )),
                    $ordered,
                    $judging,
                    $held,
                );
            },
            $order,
            $suites,
            $holding,
        );
    }

    /**
     * Why a suite cannot be in both lists, naming the first that is; nothing where none is.
     *
     * @param Listed<string>|Absent $judging
     * @param Listed<string>|Absent $holding
     */
    private static function inBoth(Listed|Absent $judging, Listed|Absent $holding): Invalid|Absent
    {
        $both = array_values(array_intersect(
            $holding instanceof Listed ? [...$holding] : [],
            $judging instanceof Listed ? [...$judging] : [],
        ));

        return $both === []
            ? Absent::setting()
            : Invalid::because(Problem::at('tests.holding', sprintf(self::IN_BOTH, $both[0])));
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

    /**
     * @template L of Learned|Absent
     *
     * @param  int|L         $seconds
     * @return Seconds|L
     */
    private static function seconds(int|Learned|Absent $seconds): Seconds|Learned|Absent
    {
        return is_int($seconds) ? Seconds::of($seconds) : $seconds;
    }
}
