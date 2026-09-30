<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

use NightWorksIO\MutationGate\Core\Config\BuiltinCiPlan;
use NightWorksIO\MutationGate\Core\File\Path;
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

    /** The one config CircleCI reads. */
    public const string CIRCLECI = '.circleci/config.yml';

    /** Each a file, or a directory ending in `/` whose files are definitions. */
    public const array PLACES = [self::GITHUB, self::GITLAB, self::BUILDKITE, '.circleci/'];

    /** The file or directory that shows a project runs this CI (ADR-0017 decision 1); none for plain JSON. */
    public static function shownBy(BuiltinCiPlan $plan): Path|NotGiven
    {
        return match ($plan) {
            BuiltinCiPlan::GitHub => Path::of(self::GITHUB),
            BuiltinCiPlan::GitLab => Path::of(self::GITLAB),
            BuiltinCiPlan::Buildkite => Path::of(self::BUILDKITE),
            BuiltinCiPlan::CircleCi => Path::of(self::CIRCLECI),
            BuiltinCiPlan::Json => NotGiven::value(),
        };
    }
}
