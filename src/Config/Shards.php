<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\Learned;
use NightWorksIO\MutationGate\Core\Config\LearnedStatistic;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/** How the work is cut (ADR-0006): `shards` and `costs`. */
final readonly class Shards implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `shards.setup`: each shard's CI setup before the gate starts, such as `1m`. */
    public static function setup(string $duration): self
    {
        return new self(Json::object(Member::of('shards', Json::object(Member::of('setup', $duration)))));
    }

    /** `shards.seconds` */
    public static function seconds(int $seconds): self
    {
        return new self(Json::at('shards.seconds', $seconds));
    }

    /** `shards.max` */
    public static function max(int $shards): self
    {
        return new self(Json::at('shards.max', $shards));
    }

    /** `shards.max: "learned"`: the most shards the default branch's recent runs ran at once, 1 to 20 (ADR-0073). */
    public static function learnedMax(): self
    {
        return new self(Json::at('shards.max', Learned::Learned->value));
    }

    /** `shards.learnedFrom: "max"`: a learned `shards.max` is the most any recent run ran at once. */
    public static function learnedFromMax(): self
    {
        return new self(Json::at('shards.learnedFrom', LearnedStatistic::Max->value));
    }

    /** `shards.learnedFrom: "median"`: a learned `shards.max` is the median of what recent runs ran at once. */
    public static function learnedFromMedian(): self
    {
        return new self(Json::at('shards.learnedFrom', LearnedStatistic::Median->value));
    }

    /** `costs.secondsPerLine`: what a line under a path prefix costs before anything was measured. */
    public static function secondsPerLine(string $prefix, int|float $seconds): self
    {
        return new self(Json::at('costs.secondsPerLine', Json::object(Member::of($prefix, $seconds))));
    }

    /** `shards.target`: the wall-clock time each shard aims at, such as `10m`, in place of `shards.seconds`. */
    public static function target(string $duration): self
    {
        return new self(Json::object(Member::of('shards', Json::object(Member::of('target', $duration)))));
    }

    /** `costs.perRunnerMinute`: what a minute of a CI runner costs, such as `0.008` `USD`. */
    public static function perRunnerMinute(int|float $amount, string $currency): self
    {
        return new self(Json::object(
            Member::of(
                'costs',
                Json::object(
                    Member::of(
                        'perRunnerMinute',
                        Json::object(Member::of('amount', $amount))->with(Member::of('currency', $currency)),
                    ),
                ),
            ),
        ));
    }

    /** `costs.savingsWindow`: how far back the summary and the badge count what the gate saved, such as `30d`. */
    public static function savingsWindow(string $duration): self
    {
        return new self(Json::object(Member::of('costs', Json::object(Member::of('savingsWindow', $duration)))));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
