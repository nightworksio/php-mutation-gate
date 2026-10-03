<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\RunName;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('names the run as its CI numbers it, or by its time where no CI does', function (
    Variables $environment,
    string $name,
): void {
    $at = Moment::at('2026-09-30T12:00:00Z');
    $base = Digest::sha256Of('base');

    expect(RunName::of($environment, $at, $base))->toEqual(Run::of($name, $at, $base));
})->with([
    'GitHub' => [
        Variables::of(['GITHUB_ACTIONS' => 'true', 'GITHUB_RUN_ID' => '5813', 'GITHUB_RUN_ATTEMPT' => '2']),
        'github:5813/2',
    ],
    'GitLab' => [Variables::of(['GITLAB_CI' => 'true', 'CI_PIPELINE_ID' => '9']), 'gitlab:9'],
    'Azure DevOps' => [Variables::of(['TF_BUILD' => 'True', 'BUILD_BUILDID' => '42']), 'azure:42'],
    'Bitbucket Pipelines' => [Variables::of(['CI' => 'true', 'BITBUCKET_BUILD_NUMBER' => '12']), 'bitbucket:12'],
    'Jenkins' => [Variables::of(['CI' => 'true', 'BUILD_TAG' => 'jenkins-gate-main-4']), 'jenkins:jenkins-gate-main-4'],
    'no CI' => [Variables::of([]), 'local:2026-09-30T12:00:00Z'],
    'a CI that numbers no run' => [Variables::of(['GITHUB_ACTIONS' => 'true', 'GITHUB_RUN_ID' => '']), 'local:2026-09-30T12:00:00Z'],
    'a CI the gate cannot read' => [Variables::of(['CI' => 'true', 'GITHUB_RUN_ID' => '5813']), 'local:2026-09-30T12:00:00Z'],
]);
