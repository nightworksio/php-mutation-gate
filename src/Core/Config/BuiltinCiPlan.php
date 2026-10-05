<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_map;

/** The CI plans this package builds in, by the name a config chooses each by. */
enum BuiltinCiPlan: string
{
    case GitHub = 'github';

    case GitLab = 'gitlab';

    case Buildkite = 'buildkite';

    case CircleCi = 'circleci';

    case Azure = 'azure';

    case Bitbucket = 'bitbucket';

    case Jenkins = 'jenkins';

    case Json = 'json';

    /** @return list<string> the names of the CI plans built in, each with a builder method of its own */
    public static function names(): array
    {
        return array_map(static fn(self $plan): string => $plan->value, self::cases());
    }

    public function named(): Name
    {
        return Name::of($this->value);
    }
}
