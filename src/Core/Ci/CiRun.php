<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Format\Series;
use NightWorksIO\MutationGate\Core\Proof\Scope;

use function preg_match;
use function rawurlencode;
use function sprintf;

/**
 * A CI run as an alert and a trace name it: the repository as owner/name,
 * the full ref it ran on, the full commit, the link to the run, and the
 * pipeline or workflow it ran in (ADR-0016, decisions 12 and 16).
 */
final readonly class CiRun
{
    private const string UNREAD = 'The gate names a run on %s, and not on this CI.';

    /** Each CI whose run the gate names, as a person names it. */
    private const array NAMED = ['GitHub', 'GitLab', 'Buildkite', 'CircleCI', 'Azure DevOps', 'Bitbucket', 'Jenkins'];

    /** A remote's URL, by its last two parts, the repository's owner and name, without `.git`. */
    private const string OWNER_AND_NAME = '#([^/:]+/[^/:]+?)(?:\.git)?/?$#D';

    private function __construct(
        private string $repository,
        private string $ref,
        private string $commit,
        private string $url,
        private string $pipeline,
        private string $id,
    ) {
    }

    public static function of(string $repository, string $ref, string $commit, string $url): self
    {
        return new self($repository, $ref, $commit, $url, '', '');
    }

    /** This run, in the pipeline or workflow of this name. */
    public function inPipeline(string $pipeline): self
    {
        return new self($this->repository, $this->ref, $this->commit, $this->url, $pipeline, $this->id);
    }

    /**
     * The run the environment of a GitHub Actions, GitLab CI, Buildkite,
     * CircleCI, Azure DevOps, Bitbucket Pipelines or Jenkins job describes;
     * why there is none in any other.
     */
    public static function read(Variables $variables): self|CannotTell
    {
        return match (true) {
            $variables->onGitHubActions() => self::github($variables),
            $variables->says(Variables::GITLAB_CI) => self::gitlab($variables),
            $variables->says(Variables::BUILDKITE) => new self(
                sprintf(
                    '%s/%s',
                    $variables->valueOf('BUILDKITE_ORGANIZATION_SLUG'),
                    $variables->valueOf('BUILDKITE_PIPELINE_SLUG'),
                ),
                self::branch($variables->valueOf('BUILDKITE_BRANCH')),
                $variables->valueOf('BUILDKITE_COMMIT'),
                $variables->valueOf('BUILDKITE_BUILD_URL'),
                $variables->valueOf('BUILDKITE_PIPELINE_NAME'),
                self::numbered('buildkite:%s', $variables->valueOf('BUILDKITE_BUILD_ID')),
            ),
            $variables->says(Variables::CIRCLECI) => new self(
                sprintf(
                    '%s/%s',
                    $variables->valueOf('CIRCLE_PROJECT_USERNAME'),
                    $variables->valueOf('CIRCLE_PROJECT_REPONAME'),
                ),
                self::branch($variables->valueOf('CIRCLE_BRANCH')),
                $variables->valueOf('CIRCLE_SHA1'),
                $variables->valueOf('CIRCLE_BUILD_URL'),
                $variables->valueOf('CIRCLE_JOB'),
                self::numbered('circleci:%s', $variables->valueOf('CIRCLE_WORKFLOW_ID')),
            ),
            $variables->has(Variables::TF_BUILD) => self::azure($variables),
            $variables->has(Variables::BITBUCKET_BUILD_NUMBER) => self::bitbucket($variables),
            $variables->has(Variables::BUILD_TAG) => self::jenkins($variables),
            default => CannotTell::because(sprintf(self::UNREAD, Series::or(...self::NAMED))),
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
        return Scope::of($this->ref)->name();
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

    /**
     * The run as a proof names it (ADR-0007 decision 3): `github:<run id>/<attempt>`, `gitlab:<pipeline id>`,
     * `buildkite:<build id>`, `circleci:<workflow id>`, `azure:<build id>`, `bitbucket:<build number>` or
     * `jenkins:<build tag>`; empty where the CI numbers none.
     */
    public function id(): string
    {
        return $this->id;
    }

    /** The name of the pipeline or workflow it ran in; empty where the CI names none. */
    public function pipeline(): string
    {
        return $this->pipeline;
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
            $variables->valueOf('GITHUB_WORKFLOW'),
            $variables->valueOf('GITHUB_RUN_ID') === '' ? '' : sprintf(
                'github:%s/%s',
                $variables->valueOf('GITHUB_RUN_ID'),
                $variables->valueOf('GITHUB_RUN_ATTEMPT'),
            ),
        );
    }

    private static function gitlab(Variables $variables): self
    {
        $tag = $variables->valueOf('CI_COMMIT_TAG');

        return new self(
            $variables->valueOf('CI_PROJECT_PATH'),
            self::refOf($tag, $variables->valueOf('CI_COMMIT_REF_NAME')),
            $variables->valueOf('CI_COMMIT_SHA'),
            $variables->valueOf('CI_PIPELINE_URL'),
            $variables->valueOf('CI_PIPELINE_NAME'),
            self::numbered('gitlab:%s', $variables->valueOf('CI_PIPELINE_ID')),
        );
    }

    private static function branch(string $name): string
    {
        return Scope::branch($name)->ref();
    }

    /** The full ref of the tag a run builds, or of its branch where it builds none. */
    private static function refOf(string $tag, string $branch): string
    {
        return $tag === '' ? self::branch($branch) : sprintf('refs/tags/%s', $tag);
    }

    private static function azure(Variables $variables): self
    {
        return new self(
            $variables->valueOf('BUILD_REPOSITORY_NAME'),
            $variables->valueOf('BUILD_SOURCEBRANCH'),
            $variables->valueOf('BUILD_SOURCEVERSION'),
            sprintf(
                '%s%s/_build/results?buildId=%s',
                $variables->valueOf('SYSTEM_COLLECTIONURI'),
                rawurlencode($variables->valueOf('SYSTEM_TEAMPROJECT')),
                $variables->valueOf('BUILD_BUILDID'),
            ),
            $variables->valueOf('BUILD_DEFINITIONNAME'),
            self::numbered('azure:%s', $variables->valueOf('BUILD_BUILDID')),
        );
    }

    private static function bitbucket(Variables $variables): self
    {
        $repository = $variables->valueOf('BITBUCKET_REPO_FULL_NAME');
        $number = $variables->valueOf(Variables::BITBUCKET_BUILD_NUMBER);
        $tag = $variables->valueOf(Variables::BITBUCKET_TAG);
        $branch = $variables->valueOf(Variables::BITBUCKET_BRANCH);

        return new self(
            $repository,
            self::refOf($tag, $branch),
            $variables->valueOf('BITBUCKET_COMMIT'),
            sprintf('https://bitbucket.org/%s/pipelines/results/%s', $repository, $number),
            '',
            self::numbered('bitbucket:%s', $number),
        );
    }

    /**
     * A Jenkins build: the repository is the last two parts of its remote's URL, or the whole URL where it has
     * fewer; the ref is the tag a build builds, else the branch a pull request comes from, else the branch; the
     * pipeline is the job.
     */
    private static function jenkins(Variables $variables): self
    {
        $remote = $variables->valueOf('GIT_URL');
        $tag = $variables->valueOf(Variables::TAG_NAME);
        $branch = $variables->has(Variables::CHANGE_BRANCH)
            ? $variables->valueOf(Variables::CHANGE_BRANCH)
            : $variables->valueOf(Variables::BRANCH_NAME);

        return new self(
            preg_match(self::OWNER_AND_NAME, $remote, $named) === 1 ? $named[1] : $remote,
            self::refOf($tag, $branch),
            $variables->valueOf('GIT_COMMIT'),
            $variables->valueOf('BUILD_URL'),
            $variables->valueOf('JOB_NAME'),
            self::numbered('jenkins:%s', $variables->valueOf(Variables::BUILD_TAG)),
        );
    }

    /** A run's number, spelt as a proof names it; empty where the CI gives none. */
    private static function numbered(string $format, string $number): string
    {
        return $number === '' ? '' : sprintf($format, $number);
    }
}
