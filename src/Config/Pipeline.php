<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/**
 * How the pipeline that runs the gate is laid out on each CI (ADR-0006, ADR-0024): the files under `ci` that hold
 * GitLab's hidden job, Buildkite's step and each CI's pipeline.
 */
final readonly class Pipeline implements Setting
{
    private function __construct(private Json $json)
    {
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

    /** `ci.bitbucket.definition`: the pipeline file that runs the gate under Bitbucket Pipelines. */
    public static function bitbucketDefinition(string $path): self
    {
        return new self(Json::at('ci.bitbucket.definition', $path));
    }

    /** `ci.jenkins.definition`: the Jenkinsfile that runs the gate under Jenkins. */
    public static function jenkinsDefinition(string $path): self
    {
        return new self(Json::at('ci.jenkins.definition', $path));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
