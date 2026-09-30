<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\GitHub;

/**
 * Whose pull requests from forks wait for a maintainer's approval before
 * their workflows run: GitHub's `approval_policy`, weakest first.
 */
enum ForkApproval: string
{
    case FirstTimeContributorsNewToGitHub = 'first_time_contributors_new_to_github';
    case FirstTimeContributors = 'first_time_contributors';
    case AllExternalContributors = 'all_external_contributors';
}
