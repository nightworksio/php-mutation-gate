<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\File\Path;

/**
 * The CI the plan is written for (ADR-0006): `ci.plan`, `ci.defaultBranch`,
 * `ci.gitlab.template` and `ci.buildkite.step`.
 */
final readonly class Ci
{
    public function __construct(
        private Choice|Absent $plan,
        private string|Absent $defaultBranch,
        private Path $gitlabTemplate,
        private string $buildkiteStep,
    ) {
    }

    /** The CI plan, or none, when it is detected from the environment. */
    public function plan(): Choice|Absent
    {
        return $this->plan;
    }

    /** The default branch, or none, when the CI or git says which it is. */
    public function defaultBranch(): string|Absent
    {
        return $this->defaultBranch;
    }

    /** The file whose hidden `.mutation-gate` job GitLab's generated jobs extend. */
    public function gitlabTemplate(): Path
    {
        return $this->gitlabTemplate;
    }

    /** The step every Buildkite step is built from, as a JSON object. */
    public function buildkiteStep(): string
    {
        return $this->buildkiteStep;
    }
}
