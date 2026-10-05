<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\BuiltinCiPlan;
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

    /** `ci.trustMergedPullRequests`: the default branch takes a merged pull request's passing run as proof. */
    public static function trustingMergedPullRequests(): self
    {
        return new self(Json::at('ci.trustMergedPullRequests', value: true));
    }

    /** `ci.trustMergedPullRequests`: the default branch re-checks a merged pull request's tree. */
    public static function notTrustingMergedPullRequests(): self
    {
        return new self(Json::at('ci.trustMergedPullRequests', value: false));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
