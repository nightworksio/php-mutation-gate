<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function intval;
use function is_int;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Runner\TighterSilence;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * `timeouts.seconds`, `timeouts.multiple` and `shards.max`, each a number or
 * learned, `timeouts.tighter` and `shards.learnedFrom`, as a JSON config and
 * the PHP builder write them.
 *
 * @internal
 */
final readonly class LimitCalls
{
    public static function secondsWritten(Seconds|Learned|Absent $seconds): int|string|Absent
    {
        return $seconds instanceof Learned ? $seconds->value : WholeSeconds::written($seconds);
    }

    /** `timeouts.tighter` as a config writes it; nothing where no layer set it. */
    public static function tighterWritten(TighterSilence|Absent $tighter): Json|Absent
    {
        return $tighter instanceof TighterSilence
            ? Json::object(
                Member::of('mutators', Json::items(...$tighter)),
                Member::of('floor', intval($tighter->floor()->seconds())),
            )
            : $tighter;
    }

    public static function multipleWritten(int|float|Learned|Absent $multiple): int|float|string|Absent
    {
        return $multiple instanceof Learned ? $multiple->value : $multiple;
    }

    /** @return list<string> the builder's call that sets `timeouts.seconds`, where a layer sets it */
    public static function seconds(Seconds|Learned|Absent $seconds): array
    {
        return match (true) {
            $seconds instanceof Seconds => [sprintf('Timeouts::seconds(%d)', intval($seconds->seconds()))],
            $seconds instanceof Learned => ['Timeouts::learnedSeconds()'],
            default => [],
        };
    }

    /** @return list<string> the builder's call that sets `timeouts.controlMost`, where a layer sets it */
    public static function controlMost(Seconds|Absent $most): array
    {
        return $most instanceof Seconds ? [sprintf('Timeouts::controlMost(%d)', intval($most->seconds()))] : [];
    }

    /** @return list<string> the builder's call that sets `timeouts.tighter`, where a layer sets it */
    public static function tighter(TighterSilence|Absent $tighter): array
    {
        return $tighter instanceof TighterSilence ? [sprintf(
            'Timeouts::tighter(%s)',
            PhpCalls::literals(intval($tighter->floor()->seconds()), ...$tighter),
        )] : [];
    }

    /** @return list<string> the builder's call that sets `timeouts.multiple`, where a layer sets it */
    public static function multiple(int|float|Learned|Absent $multiple): array
    {
        return match (true) {
            $multiple instanceof Learned => ['Timeouts::learnedMultiple()'],
            $multiple instanceof Absent => [],
            default => [sprintf('Timeouts::multiple(%s)', PhpCalls::literal($multiple))],
        };
    }

    public static function maxWritten(int|Learned|Absent $max): int|string|Absent
    {
        return $max instanceof Learned ? $max->value : $max;
    }

    public static function statisticWritten(LearnedStatistic|Absent $statistic): string|Absent
    {
        return $statistic instanceof LearnedStatistic ? $statistic->value : $statistic;
    }

    /** @return list<string> the builder's calls that set `shards.max` and `shards.learnedFrom`, where set */
    public static function max(int|Learned|Absent $max, LearnedStatistic|Absent $statistic): array
    {
        return [
            ...match (true) {
                is_int($max) => [sprintf('Shards::max(%d)', $max)],
                $max instanceof Learned => ['Shards::learnedMax()'],
                default => [],
            },
            ...$statistic instanceof LearnedStatistic ? [$statistic->php()] : [],
        ];
    }
}
