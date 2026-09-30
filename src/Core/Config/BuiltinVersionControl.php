<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Ci\Variables;

/**
 * The change sources and repositories this package builds in, by the name
 * the registry holds each by: git's, and git's with GitHub's word on what the
 * default branch already proved, where GitHub Actions runs the job.
 */
enum BuiltinVersionControl: string
{
    case Git = 'git';

    case GitHub = 'github';

    /** The one the job's CI calls for: GitHub's under GitHub Actions, git's anywhere else. */
    public static function in(Variables $environment): self
    {
        return $environment->onGitHubActions() ? self::GitHub : self::Git;
    }

    public function named(): Name
    {
        return Name::of($this->value);
    }
}
