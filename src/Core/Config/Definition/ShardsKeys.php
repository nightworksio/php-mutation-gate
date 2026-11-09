<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\LearnedStatistic;
use NightWorksIO\MutationGate\Core\Config\PathOrigin;
use NightWorksIO\MutationGate\Core\Config\Planning;
use NightWorksIO\MutationGate\Core\Config\Price;
use NightWorksIO\MutationGate\Core\Config\Shards;
use NightWorksIO\MutationGate\Core\Cost\SavingsWindow;
use NightWorksIO\MutationGate\Core\Cost\SecondsPerLine;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * How the keys a config writes are read into its `Shards` part (ADR-0002).
 *
 * @internal
 */
final readonly class ShardsKeys
{
    /** @return list<Field<Layer>> */
    public static function fields(PathOrigin $origin): array
    {
        $judges = Effect::JudgesOrReportsOnly;
        $seconds = Field::optional('seconds', Integer::atLeast(1), $judges);
        $max = Field::optional('max', OrLearned::of(Integer::atLeast(1)), $judges);
        $learnedFrom = Field::optional('learnedFrom', Enumerated::of(LearnedStatistic::cases()), $judges);
        $target = Field::optional('target', Duration::written(), $judges);
        $setup = Field::optional('setup', Duration::written(), $judges);
        $cutAt = Field::optional('cutAt', Number::atLeast(0), $judges);
        $jobLimit = Field::optional('jobLimit', Duration::written(), $judges);
        $perLine = Field::optional(SecondsPerLine::KEY, NumberMap::byPrefix(Number::atLeast(0), $origin), $judges);
        $perMinute = Field::optional('perRunnerMinute', self::price(), $judges);
        $window = Field::optional(SavingsWindow::KEY, Duration::written(), $judges);
        $model = Field::optional('model', Adapter::choosing(Builtins::costModels($origin)), $judges);
        $beforeAKill = Field::optional('testsBeforeAKill', Integer::atLeast(1), $judges);
        $surviving = Field::optional('surviving', Number::between(0, 1), $judges);
        $overhead = Field::optional('shardOverhead', Number::atLeast(1), $judges);

        return [
            Field::section(
                'shards',
                Section::of(
                    static function (Node $shards) use (
                        $seconds,
                        $max,
                        $learnedFrom,
                        $target,
                        $setup,
                        $cutAt,
                        $jobLimit,
                    ): Layer|Invalid {
                        $cut = $seconds->read($shards);
                        $most = $max->read($shards);
                        $statistic = $learnedFrom->read($shards);
                        $fit = $target->read($shards);
                        $before = $setup->read($shards);
                        $cuts = $cutAt->read($shards);
                        $limit = $jobLimit->read($shards);

                        return Reading::built(
                            static fn(): Layer => Layer::of(Shards::of(
                                seconds: self::secondsOf($cut->value()),
                                max: $most->value(),
                                target: $fit->value(),
                                setup: $before->value(),
                                learnedFrom: $statistic->value(),
                                planning: Planning::of(cutAt: $cuts->value()),
                                jobLimit: $limit->value(),
                            )),
                            $cut,
                            $most,
                            $statistic,
                            $fit,
                            $before,
                            $cuts,
                            $limit,
                        );
                    },
                    $seconds,
                    $max,
                    $learnedFrom,
                    $target,
                    $setup,
                    $cutAt,
                    $jobLimit,
                )->atMostOne(['seconds', 'target']),
            ),
            Field::section('plan', self::plan()),
            Field::section('timing', self::timing()),
            Field::section(
                'costs',
                Section::of(
                    static function (Node $costs) use (
                        $perLine,
                        $perMinute,
                        $window,
                        $model,
                        $beforeAKill,
                        $surviving,
                        $overhead,
                    ): Layer|Invalid {
                        $lines = $perLine->read($costs);
                        $minutes = $perMinute->read($costs);
                        $back = $window->read($costs);
                        $chosen = $model->read($costs);
                        $kill = $beforeAKill->read($costs);
                        $share = $surviving->read($costs);
                        $times = $overhead->read($costs);

                        return Reading::built(
                            static fn(): Layer => Layer::of(Shards::of(
                                secondsPerLine: $lines->value(),
                                perRunnerMinute: $minutes->value(),
                                savingsWindow: $back->value(),
                                costModel: $chosen->value(),
                                testsBeforeAKill: $kill->value(),
                                surviving: $share->value(),
                                shardOverhead: $times->value(),
                            )),
                            $lines,
                            $minutes,
                            $back,
                            $chosen,
                            $kill,
                            $share,
                            $times,
                        );
                    },
                    $perLine,
                    $perMinute,
                    $window,
                    $model,
                    $beforeAKill,
                    $surviving,
                    $overhead,
                ),
            ),
        ];
    }

    /** @return Section<Layer> */
    private static function plan(): Section
    {
        $chunk = Field::optional('chunkMinutes', Integer::atLeast(1), Effect::JudgesOrReportsOnly);
        $range = Field::optional('provingRange', Integer::atLeast(0), Effect::DecidesHowTheGateRuns);

        return Section::of(
            static function (Node $plan) use ($chunk, $range): Layer|Invalid {
                $minutes = $chunk->read($plan);
                $commits = $range->read($plan);

                return Reading::built(
                    static fn(): Layer => Layer::of(Shards::of(
                        planning: Planning::of($minutes->value(), $commits->value()),
                    )),
                    $minutes,
                    $commits,
                );
            },
            $chunk,
            $range,
        );
    }

    /** @return Section<Layer> */
    private static function timing(): Section
    {
        $judges = Effect::JudgesOrReportsOnly;
        $newest = Field::optional('newest', Number::aboveAndAtMost(0, 1), $judges);
        $samples = Field::optional('startUpSamples', Integer::atLeast(1), $judges);

        return Section::of(
            static function (Node $timing) use ($newest, $samples): Layer|Invalid {
                $weight = $newest->read($timing);
                $runs = $samples->read($timing);

                return Reading::built(
                    static fn(): Layer => Layer::of(Shards::of(
                        planning: Planning::of(newest: $weight->value(), startUpSamples: $runs->value()),
                    )),
                    $weight,
                    $runs,
                );
            },
            $newest,
            $samples,
        );
    }

    private static function secondsOf(int|Absent $seconds): Seconds|Absent
    {
        return $seconds instanceof Absent ? $seconds : Seconds::of($seconds);
    }

    /** @return Section<Price> */
    private static function price(): Section
    {
        $judges = Effect::JudgesOrReportsOnly;
        $amount = Field::required('amount', Number::atLeast(0), $judges);
        $currency = Field::required('currency', Text::of('a currency, such as EUR'), $judges);

        return Section::of(
            static function (Node $price) use ($amount, $currency): Price|Invalid {
                $much = $amount->read($price);
                $in = $currency->read($price);

                return Reading::built(static fn(): Price => Price::of($much->must(), $in->must()), $much, $in);
            },
            $amount,
            $currency,
        );
    }
}
