<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function intval;
use function is_int;

use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * `timeouts.seconds`, `timeouts.multiple` and `shards.max`, each a number or
 * learned, and `shards.learnedFrom`, as a JSON config and the PHP builder
 * write them.
 *
 * @internal
 */
final readonly class LimitCalls
{
    public static function secondsWritten(Seconds|Learned|Absent $seconds): int|string|Absent
    {
        return $seconds instanceof Learned ? $seconds->value : WholeSeconds::written($seconds);
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
