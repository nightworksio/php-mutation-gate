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
        Variables::of(['GITHUB_RUN_ID' => '5813', 'GITHUB_RUN_ATTEMPT' => '2', 'CI_PIPELINE_ID' => '9']),
        'github:5813/2',
    ],
    'GitLab' => [Variables::of(['CI_PIPELINE_ID' => '9', 'BUILDKITE_BUILD_ID' => 'b']), 'gitlab:9'],
    'Buildkite' => [Variables::of(['BUILDKITE_BUILD_ID' => 'b-1', 'CIRCLE_WORKFLOW_ID' => 'c']), 'buildkite:b-1'],
    'CircleCI' => [Variables::of(['CIRCLE_WORKFLOW_ID' => 'c-1']), 'circleci:c-1'],
    'no CI' => [Variables::of([]), 'local:2026-09-30T12:00:00Z'],
    'a CI variable set empty' => [Variables::of(['GITHUB_RUN_ID' => '']), 'local:2026-09-30T12:00:00Z'],
]);
