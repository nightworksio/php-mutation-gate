<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** The CI plans this package builds in, by the name a config chooses each by. */
enum BuiltinCiPlan: string
{
    case GitHub = 'github';

    case GitLab = 'gitlab';

    case Buildkite = 'buildkite';

    case CircleCi = 'circleci';

    case Json = 'json';

    public function named(): Name
    {
        return Name::of($this->value);
    }
}
