<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

/** Where the CIs the gate knows keep their definitions, from the repository's root. */
final readonly class Definitions
{
    /** Each a file, or a directory ending in `/` whose files are definitions. */
    public const array PLACES = ['.github/workflows/', '.gitlab-ci.yml', '.buildkite/', '.circleci/'];
}
