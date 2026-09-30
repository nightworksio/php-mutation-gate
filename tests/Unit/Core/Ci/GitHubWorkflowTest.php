<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Ci\GitHubWorkflow;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('takes the one-step action where a full run fits one shard, and the reusable workflow where not', function (
    float $estimate,
    GitHubWorkflow $workflow,
): void {
    expect(GitHubWorkflow::forEstimate(Seconds::of($estimate), Seconds::of(600)))->toBe($workflow);
})->with([
    'well within' => [120.0, GitHubWorkflow::Single],
    'exactly one shard' => [600.0, GitHubWorkflow::Single],
    'just over' => [600.5, GitHubWorkflow::Sharded],
]);

it('says what it is', function (): void {
    expect([GitHubWorkflow::Single->said(), GitHubWorkflow::Sharded->said()])
        ->toBe(['the one-step action', 'the reusable workflow']);
});
