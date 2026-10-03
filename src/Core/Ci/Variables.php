<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

use function array_key_exists;

/** The environment variables a CI set for a job, as the adapter that read them hands them on. */
final readonly class Variables
{
    /** Set to `true` by GitHub Actions in every job it runs. */
    public const string GITHUB_ACTIONS = 'GITHUB_ACTIONS';

    /** Set to `true` by GitLab CI in every job it runs. */
    public const string GITLAB_CI = 'GITLAB_CI';

    /** Set to `true` by Buildkite in every job it runs. */
    public const string BUILDKITE = 'BUILDKITE';

    /** Set to `true` by CircleCI in every job it runs. */
    public const string CIRCLECI = 'CIRCLECI';

    /** Set, to the build's number, by Bitbucket Pipelines in every step it runs. */
    public const string BITBUCKET_BUILD_NUMBER = 'BITBUCKET_BUILD_NUMBER';

    /** Set by Bitbucket Pipelines, to the branch a step builds, in a branch's or a pull request's pipeline. */
    public const string BITBUCKET_BRANCH = 'BITBUCKET_BRANCH';

    /** Set by Bitbucket Pipelines, to the tag a step builds, in a tag's pipeline. */
    public const string BITBUCKET_TAG = 'BITBUCKET_TAG';

    /** Set by Bitbucket Pipelines, to the pull request's id, in a pull request's pipeline. */
    public const string BITBUCKET_PR_ID = 'BITBUCKET_PR_ID';

    /** Set, to `True`, by Azure Pipelines in every job it runs. */
    public const string TF_BUILD = 'TF_BUILD';

    /** Set by every supported CI but Azure Pipelines, and unset on a developer's machine. */
    private const string CI = 'CI';

    /** @param array<string, string> $values by name */
    private function __construct(private array $values)
    {
    }

    /** @param array<string, string> $values by name */
    public static function of(array $values): self
    {
        return new self($values);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values) && $this->values[$name] !== '';
    }

    /** Whether the run is in CI, as every supported CI says by setting `CI`, and Azure Pipelines by `TF_BUILD`. */
    public function inCi(): bool
    {
        return $this->has(self::CI) || $this->has(self::TF_BUILD);
    }

    /** Whether GitHub Actions runs the job. */
    public function onGitHubActions(): bool
    {
        return $this->says(self::GITHUB_ACTIONS);
    }

    /** Whether a variable is set to `true`, as each CI sets its own to say it runs the job. */
    public function says(string $name): bool
    {
        return $this->valueOf($name) === 'true';
    }

    /** A variable's value, and nothing where it is not set. */
    public function valueOf(string $name): string
    {
        return array_key_exists($name, $this->values) ? $this->values[$name] : '';
    }
}
