<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/**
 * What a first estimate takes each unit and shard to cost before anything
 * was measured, what a minute of a CI runner costs, and how far back what
 * the gate saved is counted (ADR-0006): keys of `costs`; the model is
 * CostModel's.
 */
final readonly class Costs implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `costs.secondsPerLine`: what a line under a path prefix costs before anything was measured. */
    public static function secondsPerLine(string $prefix, int|float $seconds): self
    {
        return new self(Json::at('costs.secondsPerLine', Json::object(Member::of($prefix, $seconds))));
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

    public function written(): Json
    {
        return $this->json;
    }
}
