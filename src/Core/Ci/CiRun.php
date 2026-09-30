<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\Change\CannotTell;

use function sprintf;
use function str_starts_with;

/**
 * A CI run as an alert names it: the repository as owner/name, the full ref
 * it ran on, the full commit, and the link to the run (ADR-0016, decision 12).
 */
final readonly class CiRun
{
    private const string HEADS = 'refs/heads/';

    private const string UNREAD
        = 'This CI is not GitHub Actions, GitLab CI, Buildkite or CircleCI, so the gate cannot name its run.';

    private function __construct(
        private string $repository,
        private string $ref,
        private string $commit,
        private string $url,
    ) {
    }

    public static function of(string $repository, string $ref, string $commit, string $url): self
    {
        return new self($repository, $ref, $commit, $url);
    }

    /**
     * The run the environment of a GitHub Actions, GitLab CI, Buildkite or
     * CircleCI job describes; why there is none in any other.
     */
    public static function read(Variables $variables): self|CannotTell
    {
        return match (true) {
            $variables->valueOf('GITHUB_ACTIONS') === 'true' => self::github($variables),
            $variables->valueOf('GITLAB_CI') === 'true' => self::gitlab($variables),
            $variables->valueOf('BUILDKITE') === 'true' => new self(
                sprintf(
                    '%s/%s',
                    $variables->valueOf('BUILDKITE_ORGANIZATION_SLUG'),
                    $variables->valueOf('BUILDKITE_PIPELINE_SLUG'),
                ),
                self::branch($variables->valueOf('BUILDKITE_BRANCH')),
                $variables->valueOf('BUILDKITE_COMMIT'),
                $variables->valueOf('BUILDKITE_BUILD_URL'),
            ),
            $variables->valueOf('CIRCLECI') === 'true' => new self(
                sprintf(
                    '%s/%s',
                    $variables->valueOf('CIRCLE_PROJECT_USERNAME'),
                    $variables->valueOf('CIRCLE_PROJECT_REPONAME'),
                ),
                self::branch($variables->valueOf('CIRCLE_BRANCH')),
                $variables->valueOf('CIRCLE_SHA1'),
                $variables->valueOf('CIRCLE_BUILD_URL'),
            ),
            default => CannotTell::because(self::UNREAD),
        };
    }

    /** The repository, as `owner/name`. */
    public function repository(): string
    {
        return $this->repository;
    }

    /** The full ref, as `refs/heads/main`. */
    public function ref(): string
    {
        return $this->ref;
    }

    /** The ref as a reader says it: a branch by its name, anything else as it is. */
    public function refName(): string
    {
        return str_starts_with($this->ref, self::HEADS) ? mb_substr($this->ref, mb_strlen(self::HEADS)) : $this->ref;
    }

    /** The full commit SHA. */
    public function commit(): string
    {
        return $this->commit;
    }

    public function url(): string
    {
        return $this->url;
    }

    private static function github(Variables $variables): self
    {
        $server = $variables->valueOf('GITHUB_SERVER_URL');
        $repository = $variables->valueOf('GITHUB_REPOSITORY');

        return new self(
            $repository,
            $variables->valueOf('GITHUB_REF'),
            $variables->valueOf('GITHUB_SHA'),
            sprintf(
                '%s/%s/actions/runs/%s',
                $server === '' ? 'https://github.com' : $server,
                $repository,
                $variables->valueOf('GITHUB_RUN_ID'),
            ),
        );
    }

    private static function gitlab(Variables $variables): self
    {
        $tag = $variables->valueOf('CI_COMMIT_TAG');

        return new self(
            $variables->valueOf('CI_PROJECT_PATH'),
            $tag === '' ? self::branch($variables->valueOf('CI_COMMIT_REF_NAME')) : sprintf('refs/tags/%s', $tag),
            $variables->valueOf('CI_COMMIT_SHA'),
            $variables->valueOf('CI_PIPELINE_URL'),
        );
    }

    private static function branch(string $name): string
    {
        return sprintf('%s%s', self::HEADS, $name);
    }
}
