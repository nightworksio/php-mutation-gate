<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\CiRun;
use NightWorksIO\MutationGate\Core\Ci\Variables;

it('holds the repository, the full ref and commit, and the link to the run', function (): void {
    $run = CiRun::of('octo/gate', 'refs/heads/main', '5eeca8f0d2b1', 'https://ci.example/7');

    expect($run->repository())->toBe('octo/gate')
        ->and($run->ref())->toBe('refs/heads/main')
        ->and($run->commit())->toBe('5eeca8f0d2b1')
        ->and($run->url())->toBe('https://ci.example/7');
});

it('names a branch by its name, and any other ref as it is', function (): void {
    expect(CiRun::of('o/r', 'refs/heads/release/1.x', 'c', 'u')->refName())->toBe('release/1.x')
        ->and(CiRun::of('o/r', 'refs/tags/v1', 'c', 'u')->refName())->toBe('refs/tags/v1');
});

it('reads the run from the environment of each CI the gate knows', function (Variables $environment, CiRun $run): void {
    $read = CiRun::read($environment);
    $fields = static fn(CiRun $run): array => [$run->repository(), $run->ref(), $run->commit(), $run->url()];

    expect($read instanceof CiRun ? $fields($read) : $read)->toBe($fields($run));
})->with([
    'GitHub Actions' => [
        fn(): Variables => Variables::of(['GITHUB_ACTIONS' => 'true', 'GITHUB_REPOSITORY' => 'octo/gate', 'GITHUB_REF' => 'refs/heads/main', 'GITHUB_SHA' => 'abc', 'GITHUB_SERVER_URL' => 'https://github.example', 'GITHUB_RUN_ID' => '7']),
        fn(): CiRun => CiRun::of('octo/gate', 'refs/heads/main', 'abc', 'https://github.example/octo/gate/actions/runs/7'),
    ],
    'GitHub Actions on github.com' => [
        fn(): Variables => Variables::of(['GITHUB_ACTIONS' => 'true', 'GITHUB_REPOSITORY' => 'octo/gate', 'GITHUB_REF' => 'refs/heads/main', 'GITHUB_SHA' => 'abc', 'GITHUB_RUN_ID' => '7']),
        fn(): CiRun => CiRun::of('octo/gate', 'refs/heads/main', 'abc', 'https://github.com/octo/gate/actions/runs/7'),
    ],
    'GitLab CI on a branch' => [
        fn(): Variables => Variables::of(['GITLAB_CI' => 'true', 'CI_PROJECT_PATH' => 'octo/gate', 'CI_COMMIT_REF_NAME' => 'main', 'CI_COMMIT_SHA' => 'abc', 'CI_PIPELINE_URL' => 'https://gitlab.example/octo/gate/-/pipelines/9']),
        fn(): CiRun => CiRun::of('octo/gate', 'refs/heads/main', 'abc', 'https://gitlab.example/octo/gate/-/pipelines/9'),
    ],
    'GitLab CI on a tag' => [
        fn(): Variables => Variables::of(['GITLAB_CI' => 'true', 'CI_PROJECT_PATH' => 'octo/gate', 'CI_COMMIT_REF_NAME' => 'v1.0.0', 'CI_COMMIT_TAG' => 'v1.0.0', 'CI_COMMIT_SHA' => 'abc', 'CI_PIPELINE_URL' => 'u']),
        fn(): CiRun => CiRun::of('octo/gate', 'refs/tags/v1.0.0', 'abc', 'u'),
    ],
    'Buildkite' => [
        fn(): Variables => Variables::of(['BUILDKITE' => 'true', 'BUILDKITE_ORGANIZATION_SLUG' => 'octo', 'BUILDKITE_PIPELINE_SLUG' => 'gate', 'BUILDKITE_BRANCH' => 'main', 'BUILDKITE_COMMIT' => 'abc', 'BUILDKITE_BUILD_URL' => 'https://buildkite.example/octo/gate/builds/3']),
        fn(): CiRun => CiRun::of('octo/gate', 'refs/heads/main', 'abc', 'https://buildkite.example/octo/gate/builds/3'),
    ],
    'CircleCI' => [
        fn(): Variables => Variables::of(['CIRCLECI' => 'true', 'CIRCLE_PROJECT_USERNAME' => 'octo', 'CIRCLE_PROJECT_REPONAME' => 'gate', 'CIRCLE_BRANCH' => 'main', 'CIRCLE_SHA1' => 'abc', 'CIRCLE_BUILD_URL' => 'https://circleci.example/gh/octo/gate/5']),
        fn(): CiRun => CiRun::of('octo/gate', 'refs/heads/main', 'abc', 'https://circleci.example/gh/octo/gate/5'),
    ],
    'Azure DevOps' => [
        fn(): Variables => Variables::of(['TF_BUILD' => 'True', 'BUILD_REPOSITORY_NAME' => 'octo/gate', 'BUILD_SOURCEBRANCH' => 'refs/heads/main', 'BUILD_SOURCEVERSION' => 'abc', 'SYSTEM_COLLECTIONURI' => 'https://dev.azure.com/octo/', 'SYSTEM_TEAMPROJECT' => 'Gate Project', 'BUILD_BUILDID' => '42']),
        fn(): CiRun => CiRun::of('octo/gate', 'refs/heads/main', 'abc', 'https://dev.azure.com/octo/Gate%20Project/_build/results?buildId=42'),
    ],
    'Bitbucket Pipelines on a branch' => [
        fn(): Variables => Variables::of(['BITBUCKET_BUILD_NUMBER' => '12', 'BITBUCKET_REPO_FULL_NAME' => 'octo/gate', 'BITBUCKET_BRANCH' => 'main', 'BITBUCKET_COMMIT' => 'abc']),
        fn(): CiRun => CiRun::of('octo/gate', 'refs/heads/main', 'abc', 'https://bitbucket.org/octo/gate/pipelines/results/12'),
    ],
    'Bitbucket Pipelines on a tag' => [
        fn(): Variables => Variables::of(['BITBUCKET_BUILD_NUMBER' => '12', 'BITBUCKET_REPO_FULL_NAME' => 'octo/gate', 'BITBUCKET_TAG' => 'v1.0.0', 'BITBUCKET_COMMIT' => 'abc']),
        fn(): CiRun => CiRun::of('octo/gate', 'refs/tags/v1.0.0', 'abc', 'https://bitbucket.org/octo/gate/pipelines/results/12'),
    ],
    'Jenkins on a branch, cloned over HTTPS' => [
        fn(): Variables => Variables::of(['BUILD_TAG' => 'jenkins-octo-gate-main-4', 'GIT_URL' => 'https://github.com/octo/gate.git', 'BRANCH_NAME' => 'main', 'GIT_COMMIT' => 'abc', 'BUILD_URL' => 'https://jenkins.example/job/octo/job/gate/job/main/4/']),
        fn(): CiRun => CiRun::of('octo/gate', 'refs/heads/main', 'abc', 'https://jenkins.example/job/octo/job/gate/job/main/4/'),
    ],
    'Jenkins on a pull request, cloned over SSH' => [
        fn(): Variables => Variables::of(['BUILD_TAG' => 'jenkins-octo-gate-PR-7-1', 'GIT_URL' => 'git@github.com:octo/gate.git', 'BRANCH_NAME' => 'PR-7', 'CHANGE_ID' => '7', 'CHANGE_BRANCH' => 'feature/x', 'GIT_COMMIT' => 'abc', 'BUILD_URL' => 'u']),
        fn(): CiRun => CiRun::of('octo/gate', 'refs/heads/feature/x', 'abc', 'u'),
    ],
    'Jenkins on a tag, from a remote of one part' => [
        fn(): Variables => Variables::of(['BUILD_TAG' => 'jenkins-gate-v1.0.0-1', 'GIT_URL' => 'gate', 'BRANCH_NAME' => 'v1.0.0', 'TAG_NAME' => 'v1.0.0', 'GIT_COMMIT' => 'abc', 'BUILD_URL' => 'u']),
        fn(): CiRun => CiRun::of('gate', 'refs/tags/v1.0.0', 'abc', 'u'),
    ],
]);

it('names the run as a proof names it, as each CI numbers it, and none where it numbers none', function (
    Variables $environment,
    string $id,
): void {
    $run = CiRun::read($environment);

    expect($run instanceof CiRun ? $run->id() : $run)->toBe($id);
})->with([
    'GitHub Actions' => [fn(): Variables => Variables::of(['GITHUB_ACTIONS' => 'true', 'GITHUB_RUN_ID' => '5813', 'GITHUB_RUN_ATTEMPT' => '2']), 'github:5813/2'],
    'GitLab CI' => [fn(): Variables => Variables::of(['GITLAB_CI' => 'true', 'CI_PIPELINE_ID' => '9']), 'gitlab:9'],
    'Buildkite' => [fn(): Variables => Variables::of(['BUILDKITE' => 'true', 'BUILDKITE_BUILD_ID' => 'b-1']), 'buildkite:b-1'],
    'CircleCI' => [fn(): Variables => Variables::of(['CIRCLECI' => 'true', 'CIRCLE_WORKFLOW_ID' => 'c-1']), 'circleci:c-1'],
    'Azure DevOps' => [fn(): Variables => Variables::of(['TF_BUILD' => 'True', 'BUILD_BUILDID' => '42']), 'azure:42'],
    'Bitbucket Pipelines' => [fn(): Variables => Variables::of(['BITBUCKET_BUILD_NUMBER' => '12']), 'bitbucket:12'],
    'Jenkins' => [fn(): Variables => Variables::of(['BUILD_TAG' => 'jenkins-octo-gate-main-4']), 'jenkins:jenkins-octo-gate-main-4'],
    'GitHub Actions with no run' => [fn(): Variables => Variables::of(['GITHUB_ACTIONS' => 'true']), ''],
    'GitLab CI with no pipeline' => [fn(): Variables => Variables::of(['GITLAB_CI' => 'true']), ''],
    'Azure DevOps with no build' => [fn(): Variables => Variables::of(['TF_BUILD' => 'True']), ''],
]);

it('cannot name the run of any other CI', function (): void {
    expect(CiRun::read(Variables::of(['CI' => 'true', 'GITHUB_ACTIONS' => 'false'])))
        ->toEqual(CannotTell::because('The gate names a run on GitHub, GitLab, Buildkite, CircleCI, Azure DevOps, Bitbucket or Jenkins, and not on this CI.'));
});

it('names the pipeline or workflow each CI names, and none where it names none', function (Variables $environment, string $pipeline): void {
    $run = CiRun::read($environment);

    expect($run instanceof CiRun ? $run->pipeline() : null)->toBe($pipeline);
})->with([
    'GitHub Actions' => [fn(): Variables => Variables::of(['GITHUB_ACTIONS' => 'true', 'GITHUB_WORKFLOW' => 'mutation']), 'mutation'],
    'GitLab CI' => [fn(): Variables => Variables::of(['GITLAB_CI' => 'true', 'CI_PIPELINE_NAME' => 'nightly']), 'nightly'],
    'Buildkite' => [fn(): Variables => Variables::of(['BUILDKITE' => 'true', 'BUILDKITE_PIPELINE_NAME' => 'Gate']), 'Gate'],
    'CircleCI' => [fn(): Variables => Variables::of(['CIRCLECI' => 'true', 'CIRCLE_JOB' => 'mutate']), 'mutate'],
    'Azure DevOps' => [fn(): Variables => Variables::of(['TF_BUILD' => 'True', 'BUILD_DEFINITIONNAME' => 'gate-ci']), 'gate-ci'],
    'Bitbucket Pipelines, which names none' => [fn(): Variables => Variables::of(['BITBUCKET_BUILD_NUMBER' => '12']), ''],
    'Jenkins, by the job' => [fn(): Variables => Variables::of(['BUILD_TAG' => 'jenkins-octo-gate-main-4', 'JOB_NAME' => 'octo/gate/main']), 'octo/gate/main'],
    'none' => [fn(): Variables => Variables::of(['GITHUB_ACTIONS' => 'true']), ''],
]);

it('takes a pipeline\'s name, keeping the run\'s number', function (): void {
    $numbered = CiRun::read(Variables::of(['GITLAB_CI' => 'true', 'CI_PIPELINE_ID' => '9']));

    expect(CiRun::of('o/r', 'refs/heads/main', 'c', 'u')->inPipeline('mutation')->pipeline())->toBe('mutation')
        ->and(CiRun::of('o/r', 'refs/heads/main', 'c', 'u')->pipeline())->toBe('')
        ->and(CiRun::of('o/r', 'refs/heads/main', 'c', 'u')->id())->toBe('')
        ->and($numbered instanceof CiRun ? $numbered->inPipeline('mutation')->id() : $numbered)->toBe('gitlab:9');
});
