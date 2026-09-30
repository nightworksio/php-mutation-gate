<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Ci\Variables;

/** The CI plans this package builds in, by the name a config chooses each by. */
enum BuiltinCiPlan: string
{
    case GitHub = 'github';

    case GitLab = 'gitlab';

    case Buildkite = 'buildkite';

    case CircleCi = 'circleci';

    case Json = 'json';

    /**
     * The plan of the CI the job runs in, as the variable each CI sets in every job says: GitHub Actions, then
     * GitLab CI, Buildkite and CircleCI; the JSON plan anywhere else.
     */
    public static function detected(Variables $environment): self
    {
        return match (true) {
            $environment->onGitHubActions() => self::GitHub,
            $environment->says(Variables::GITLAB_CI) => self::GitLab,
            $environment->says(Variables::BUILDKITE) => self::Buildkite,
            $environment->says(Variables::CIRCLECI) => self::CircleCi,
            default => self::Json,
        };
    }

    public function named(): Name
    {
        return Name::of($this->value);
    }
}
