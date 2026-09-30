<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\BuiltinCiPlan;
use NightWorksIO\MutationGate\Core\Config\Name;

it('names each built-in CI plan by the name a config chooses it by', function (BuiltinCiPlan $builtin): void {
    expect($builtin->named())->toEqual(Name::of($builtin->value));
})->with(BuiltinCiPlan::cases());

it('detects the plan of the CI the job runs in, and the JSON plan anywhere else', function (
    Variables $variables,
    BuiltinCiPlan $plan,
): void {
    expect(BuiltinCiPlan::detected($variables))->toBe($plan);
})->with([
    'GitHub Actions' => [Variables::of(['GITHUB_ACTIONS' => 'true']), BuiltinCiPlan::GitHub],
    'GitLab CI' => [Variables::of(['GITLAB_CI' => 'true']), BuiltinCiPlan::GitLab],
    'Buildkite' => [Variables::of(['BUILDKITE' => 'true']), BuiltinCiPlan::Buildkite],
    'CircleCI' => [Variables::of(['CIRCLECI' => 'true']), BuiltinCiPlan::CircleCi],
    'GitHub Actions before any other' => [Variables::of(['GITLAB_CI' => 'true', 'GITHUB_ACTIONS' => 'true']), BuiltinCiPlan::GitHub],
    'GitLab CI before Buildkite' => [Variables::of(['BUILDKITE' => 'true', 'GITLAB_CI' => 'true']), BuiltinCiPlan::GitLab],
    'Buildkite before CircleCI' => [Variables::of(['CIRCLECI' => 'true', 'BUILDKITE' => 'true']), BuiltinCiPlan::Buildkite],
    'a variable not set to true' => [Variables::of(['GITLAB_CI' => '1']), BuiltinCiPlan::Json],
    'no CI' => [Variables::of([]), BuiltinCiPlan::Json],
]);
