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

    /** `shards.cutAt`: how many times its overhead a shard's share of the cost must be at least; 2.0. */
    public static function cutAt(float $times): self
    {
        return new self(Json::at('shards.cutAt', $times));
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

    /** `shards.jobLimit`: how long a shard's job can run, such as `50m`: it is cut to fit it, and stops at it. */
    public static function jobLimit(string $duration): self
    {
        return new self(Json::object(Member::of('shards', Json::object(Member::of('jobLimit', $duration)))));
    }

    /** `costs.testsBeforeAKill`: how many covering tests of average time a killed mutant is first expected to run. */
    public static function testsBeforeAKill(int $tests): self
    {
        return new self(Json::at('costs.testsBeforeAKill', $tests));
    }

    /** `costs.surviving`: the share of mutants, from 0 to 1, a first estimate expects to run every covering test. */
    public static function surviving(int|float $share): self
    {
        return new self(Json::at('costs.surviving', $share));
    }

    /** `costs.shardOverhead`: how many times its mutants' own runs a first estimate takes a shard to be. */
    public static function shardOverhead(int|float $times): self
    {
        return new self(Json::at('costs.shardOverhead', $times));
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

    /** `shards.pull` `auto`, as by default: shards claim where the claims passed the race test, else steal. */
    public static function pullingAuto(): self
    {
        return new self(Json::at('shards.pull', 'auto'));
    }

    /** `shards.pull` `claim`: a shard claims each item by an artifact of its own name before it runs it. */
    public static function pullingByClaims(): self
    {
        return new self(Json::at('shards.pull', 'claim'));
    }

    /** `shards.pull` `steal`: a shard takes items from the back of the longest list left, marking what it finished. */
    public static function pullingBySteals(): self
    {
        return new self(Json::at('shards.pull', 'steal'));
    }

    /** `shards.pull` `off`: each shard runs its own planned part. */
    public static function owningTheirParts(): self
    {
        return new self(Json::at('shards.pull', 'off'));
    }

    /** `shards.lastJudges`: the shard that finishes the last item computes the verdict. */
    public static function lastJudging(): self
    {
        return new self(Json::at('shards.lastJudges', value: true));
    }

    /** `shards.lastJudges` off, as by default: the verdict job computes the verdict. */
    public static function verdictJobJudging(): self
    {
        return new self(Json::at('shards.lastJudges', value: false));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
