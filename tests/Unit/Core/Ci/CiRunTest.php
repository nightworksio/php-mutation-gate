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
        Variables::of(['GITHUB_ACTIONS' => 'true', 'GITHUB_REPOSITORY' => 'octo/gate', 'GITHUB_REF' => 'refs/heads/main', 'GITHUB_SHA' => 'abc', 'GITHUB_SERVER_URL' => 'https://github.example', 'GITHUB_RUN_ID' => '7']),
        CiRun::of('octo/gate', 'refs/heads/main', 'abc', 'https://github.example/octo/gate/actions/runs/7'),
    ],
    'GitHub Actions on github.com' => [
        Variables::of(['GITHUB_ACTIONS' => 'true', 'GITHUB_REPOSITORY' => 'octo/gate', 'GITHUB_REF' => 'refs/heads/main', 'GITHUB_SHA' => 'abc', 'GITHUB_RUN_ID' => '7']),
        CiRun::of('octo/gate', 'refs/heads/main', 'abc', 'https://github.com/octo/gate/actions/runs/7'),
    ],
    'GitLab CI on a branch' => [
        Variables::of(['GITLAB_CI' => 'true', 'CI_PROJECT_PATH' => 'octo/gate', 'CI_COMMIT_REF_NAME' => 'main', 'CI_COMMIT_SHA' => 'abc', 'CI_PIPELINE_URL' => 'https://gitlab.example/octo/gate/-/pipelines/9']),
        CiRun::of('octo/gate', 'refs/heads/main', 'abc', 'https://gitlab.example/octo/gate/-/pipelines/9'),
    ],
    'GitLab CI on a tag' => [
        Variables::of(['GITLAB_CI' => 'true', 'CI_PROJECT_PATH' => 'octo/gate', 'CI_COMMIT_REF_NAME' => 'v1.0.0', 'CI_COMMIT_TAG' => 'v1.0.0', 'CI_COMMIT_SHA' => 'abc', 'CI_PIPELINE_URL' => 'u']),
        CiRun::of('octo/gate', 'refs/tags/v1.0.0', 'abc', 'u'),
    ],
    'Buildkite' => [
        Variables::of(['BUILDKITE' => 'true', 'BUILDKITE_ORGANIZATION_SLUG' => 'octo', 'BUILDKITE_PIPELINE_SLUG' => 'gate', 'BUILDKITE_BRANCH' => 'main', 'BUILDKITE_COMMIT' => 'abc', 'BUILDKITE_BUILD_URL' => 'https://buildkite.example/octo/gate/builds/3']),
        CiRun::of('octo/gate', 'refs/heads/main', 'abc', 'https://buildkite.example/octo/gate/builds/3'),
    ],
    'CircleCI' => [
        Variables::of(['CIRCLECI' => 'true', 'CIRCLE_PROJECT_USERNAME' => 'octo', 'CIRCLE_PROJECT_REPONAME' => 'gate', 'CIRCLE_BRANCH' => 'main', 'CIRCLE_SHA1' => 'abc', 'CIRCLE_BUILD_URL' => 'https://circleci.example/gh/octo/gate/5']),
        CiRun::of('octo/gate', 'refs/heads/main', 'abc', 'https://circleci.example/gh/octo/gate/5'),
    ],
    'Azure DevOps' => [
        Variables::of(['TF_BUILD' => 'True', 'BUILD_REPOSITORY_NAME' => 'octo/gate', 'BUILD_SOURCEBRANCH' => 'refs/heads/main', 'BUILD_SOURCEVERSION' => 'abc', 'SYSTEM_COLLECTIONURI' => 'https://dev.azure.com/octo/', 'SYSTEM_TEAMPROJECT' => 'Gate Project', 'BUILD_BUILDID' => '42']),
        CiRun::of('octo/gate', 'refs/heads/main', 'abc', 'https://dev.azure.com/octo/Gate%20Project/_build/results?buildId=42'),
    ],
]);

it('names the run as a proof names it, as each CI numbers it, and none where it numbers none', function (
    Variables $environment,
    string $id,
): void {
    $run = CiRun::read($environment);

    expect($run instanceof CiRun ? $run->id() : $run)->toBe($id);
})->with([
    'GitHub Actions' => [Variables::of(['GITHUB_ACTIONS' => 'true', 'GITHUB_RUN_ID' => '5813', 'GITHUB_RUN_ATTEMPT' => '2']), 'github:5813/2'],
    'GitLab CI' => [Variables::of(['GITLAB_CI' => 'true', 'CI_PIPELINE_ID' => '9']), 'gitlab:9'],
    'Buildkite' => [Variables::of(['BUILDKITE' => 'true', 'BUILDKITE_BUILD_ID' => 'b-1']), 'buildkite:b-1'],
    'CircleCI' => [Variables::of(['CIRCLECI' => 'true', 'CIRCLE_WORKFLOW_ID' => 'c-1']), 'circleci:c-1'],
    'Azure DevOps' => [Variables::of(['TF_BUILD' => 'True', 'BUILD_BUILDID' => '42']), 'azure:42'],
    'GitHub Actions with no run' => [Variables::of(['GITHUB_ACTIONS' => 'true']), ''],
    'GitLab CI with no pipeline' => [Variables::of(['GITLAB_CI' => 'true']), ''],
    'Azure DevOps with no build' => [Variables::of(['TF_BUILD' => 'True']), ''],
]);

it('cannot name the run of any other CI', function (): void {
    expect(CiRun::read(Variables::of(['CI' => 'true', 'GITHUB_ACTIONS' => 'false'])))
        ->toEqual(CannotTell::because('This CI is not GitHub Actions, GitLab, Buildkite, CircleCI or Azure DevOps: the gate cannot name its run.'));
});

it('names the pipeline or workflow each CI names, and none where it names none', function (Variables $environment, string $pipeline): void {
    $run = CiRun::read($environment);

    expect($run instanceof CiRun ? $run->pipeline() : null)->toBe($pipeline);
})->with([
    'GitHub Actions' => [Variables::of(['GITHUB_ACTIONS' => 'true', 'GITHUB_WORKFLOW' => 'mutation']), 'mutation'],
    'GitLab CI' => [Variables::of(['GITLAB_CI' => 'true', 'CI_PIPELINE_NAME' => 'nightly']), 'nightly'],
    'Buildkite' => [Variables::of(['BUILDKITE' => 'true', 'BUILDKITE_PIPELINE_NAME' => 'Gate']), 'Gate'],
    'CircleCI' => [Variables::of(['CIRCLECI' => 'true', 'CIRCLE_JOB' => 'mutate']), 'mutate'],
    'Azure DevOps' => [Variables::of(['TF_BUILD' => 'True', 'BUILD_DEFINITIONNAME' => 'gate-ci']), 'gate-ci'],
    'none' => [Variables::of(['GITHUB_ACTIONS' => 'true']), ''],
]);

it('takes a pipeline\'s name, keeping the run\'s number', function (): void {
    $numbered = CiRun::read(Variables::of(['GITLAB_CI' => 'true', 'CI_PIPELINE_ID' => '9']));

    expect(CiRun::of('o/r', 'refs/heads/main', 'c', 'u')->inPipeline('mutation')->pipeline())->toBe('mutation')
        ->and(CiRun::of('o/r', 'refs/heads/main', 'c', 'u')->pipeline())->toBe('')
        ->and(CiRun::of('o/r', 'refs/heads/main', 'c', 'u')->id())->toBe('')
        ->and($numbered instanceof CiRun ? $numbered->inPipeline('mutation')->id() : $numbered)->toBe('gitlab:9');
});
