<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\BuiltinCiPlan;
use NightWorksIO\MutationGate\Core\Config\MergedTrust;
use NightWorksIO\MutationGate\Core\Config\OwnScopeTrust;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/**
 * The CI the plan is written for, its default branch, its verdict's check and the trust in a merged pull request
 * (ADR-0005, ADR-0006, ADR-0007): `ci`. `Pipeline` lays out the pipeline that runs the gate.
 */
final readonly class Ci implements Setting
{
    private function __construct(private Json $json)
    {
    }

    public static function github(): self
    {
        return new self(Json::at('ci.plan', BuiltinCiPlan::GitHub->value));
    }

    public static function gitlab(): self
    {
        return new self(Json::at('ci.plan', BuiltinCiPlan::GitLab->value));
    }

    public static function buildkite(): self
    {
        return new self(Json::at('ci.plan', BuiltinCiPlan::Buildkite->value));
    }

    public static function circleci(): self
    {
        return new self(Json::at('ci.plan', BuiltinCiPlan::CircleCi->value));
    }

    public static function azure(): self
    {
        return new self(Json::at('ci.plan', BuiltinCiPlan::Azure->value));
    }

    public static function bitbucket(): self
    {
        return new self(Json::at('ci.plan', BuiltinCiPlan::Bitbucket->value));
    }

    public static function jenkins(): self
    {
        return new self(Json::at('ci.plan', BuiltinCiPlan::Jenkins->value));
    }

    /** The plan as JSON, for any other CI. */
    public static function json(): self
    {
        return new self(Json::at('ci.plan', BuiltinCiPlan::Json->value));
    }

    /** A CI plan another extension registers by name, or a class, with its options. */
    public static function uses(string $plan, Option ...$options): self
    {
        return new self(
            Json::object(Member::of('ci', Json::object(Member::of('plan', Option::choice($plan, ...$options))))),
        );
    }

    /** `ci.defaultBranch` */
    public static function defaultBranch(string $branch): self
    {
        return new self(Json::at('ci.defaultBranch', $branch));
    }

    /** `ci.check`: the check-run name the verdict reports under. */
    public static function check(string $name): self
    {
        return new self(Json::object(Member::of('ci', Json::object(Member::of('check', $name)))));
    }

    /** `ci.schedule`: the cron schedule of the full run `init --ci` writes, weekly unless this says. */
    public static function schedule(string $cron): self
    {
        return new self(Json::at('ci.schedule', $cron));
    }

    /**
     * `ci.trustMergedPullRequests`: the default branch takes a merged pull request's own recorded pass as proof of
     * its tree, which trusts that pull request's run.
     */
    public static function trustingMergedPullRequests(): self
    {
        return new self(Json::at('ci.trustMergedPullRequests', value: true));
    }

    /** `ci.trustMergedPullRequests`: the default branch re-checks a merged pull request's tree, as by default. */
    public static function notTrustingMergedPullRequests(): self
    {
        return new self(Json::at('ci.trustMergedPullRequests', value: false));
    }

    /**
     * `ci.trustMergedPullRequests`: `delta`, which also takes the pass of a pull request whose head is not the
     * landed tree, and reaches only the change from that head to it.
     */
    public static function trustingMergedDeltas(): self
    {
        return new self(Json::at('ci.trustMergedPullRequests', MergedTrust::Delta->value));
    }

    /**
     * `ci.mergedOwnScopeProofs`: `reach`, as by default: a merged pull request's pass carries its own results, and the
     * units it took from its own scope's proofs are run again.
     */
    public static function reachingMergedOwnScopeProofs(): self
    {
        return new self(Json::at('ci.mergedOwnScopeProofs', OwnScopeTrust::Reach->value));
    }

    /**
     * `ci.mergedOwnScopeProofs`: `refuse`: a merged pull request's pass that took any own-scope proof spares nothing.
     */
    public static function refusingMergedOwnScopeProofs(): self
    {
        return new self(Json::at('ci.mergedOwnScopeProofs', OwnScopeTrust::Refuse->value));
    }

    /** `ci.mergedOwnScopeProofs`: `trust`: a merged pull request's pass is taken whole, own-scope proofs included. */
    public static function trustingMergedOwnScopeProofs(): self
    {
        return new self(Json::at('ci.mergedOwnScopeProofs', OwnScopeTrust::Trust->value));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
