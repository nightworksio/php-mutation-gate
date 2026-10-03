<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

use function is_string;

use NightWorksIO\MutationGate\Core\Config\BuiltinCiPlan;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\NotGiven;

/** Where the CIs the gate knows keep their definitions, from the repository's root. */
final readonly class Definitions
{
    /** The directory of GitHub's workflows. */
    public const string GITHUB = '.github/workflows/';

    /** The pipeline GitLab runs. */
    public const string GITLAB = '.gitlab-ci.yml';

    /** The directory of Buildkite's pipelines. */
    public const string BUILDKITE = '.buildkite/';

    /** The pipeline Azure DevOps runs where none is named: `ci.azure.definition`'s default. */
    public const string AZURE = 'azure-pipelines.yml';

    /** The pipeline Bitbucket Pipelines runs, and `ci.bitbucket.definition`'s default. */
    public const string BITBUCKET = 'bitbucket-pipelines.yml';

    /** The pipeline Jenkins runs where a job names none, and `ci.jenkins.definition`'s default. */
    public const string JENKINS = 'Jenkinsfile';

    /** The directory of CircleCI's config. */
    public const string CIRCLECI_DIRECTORY = '.circleci/';

    /** The one config CircleCI reads. */
    public const string CIRCLECI = '.circleci/config.yml';

    /**
     * Where the CI keeps its definitions, which shows a project runs it (ADR-0017 decision 1): a file, or a
     * directory ending in `/` whose files are definitions; none for plain JSON.
     */
    public static function shownBy(BuiltinCiPlan $plan): string|NotGiven
    {
        return match ($plan) {
            BuiltinCiPlan::GitHub => self::GITHUB,
            BuiltinCiPlan::GitLab => self::GITLAB,
            BuiltinCiPlan::Buildkite => self::BUILDKITE,
            BuiltinCiPlan::CircleCi => self::CIRCLECI_DIRECTORY,
            BuiltinCiPlan::Azure => self::AZURE,
            BuiltinCiPlan::Bitbucket => self::BITBUCKET,
            BuiltinCiPlan::Jenkins => self::JENKINS,
            BuiltinCiPlan::Json => NotGiven::value(),
        };
    }

    /**
     * Where every CI the gate knows keeps its definitions, as `shownBy()` names each.
     *
     * @return Listed<string>
     */
    public static function places(): Listed
    {
        $places = [];

        foreach (BuiltinCiPlan::cases() as $plan) {
            $place = self::shownBy($plan);
            $places = is_string($place) ? [...$places, $place] : $places;
        }

        return Listed::of(...$places);
    }
}
