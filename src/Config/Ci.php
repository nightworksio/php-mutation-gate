<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\BuiltinCiPlan;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/** The CI the plan is written for (ADR-0006): `ci`. */
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

    /** `ci.gitlab.template`: the file whose hidden `.mutation-gate` job the generated jobs extend. */
    public static function gitlabTemplate(string $path): self
    {
        return new self(Json::at('ci.gitlab.template', $path));
    }

    /** `ci.buildkite.step`: the step every generated Buildkite step is built from. */
    public static function buildkiteStep(Option ...$keys): self
    {
        return new self(Json::object(
            Member::of(
                'ci',
                Json::object(
                    Member::of(
                        'buildkite',
                        Json::object(Member::of('step', Option::object(...$keys))),
                    ),
                ),
            ),
        ));
    }

    /** `ci.check`: the check-run name the verdict reports under. */
    public static function check(string $name): self
    {
        return new self(Json::object(Member::of('ci', Json::object(Member::of('check', $name)))));
    }

    /** `ci.buildkite.definition`: the pipeline file that runs the gate under Buildkite. */
    public static function buildkiteDefinition(string $path): self
    {
        return new self(Json::object(
            Member::of(
                'ci',
                Json::object(Member::of('buildkite', Json::object(Member::of('definition', $path)))),
            ),
        ));
    }

    /** `ci.azure.definition`: the pipeline file that runs the gate under Azure DevOps. */
    public static function azureDefinition(string $path): self
    {
        return new self(Json::at('ci.azure.definition', $path));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
