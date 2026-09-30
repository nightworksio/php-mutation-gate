<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Doctor\Check\ForkApprovalPolicy;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\GitHub\ForkApproval;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;
use NightWorksIO\MutationGate\Tests\Support\Online;

it('advises where a fork\'s workflows run without approval for some contributors', function (ForkApproval $policy, string $who): void {
    $settings = Online::settings(Listed::of('mutation / verdict'), $policy, Online::ranAt('2026-09-29T03:00:00Z'));

    expect(ForkApprovalPolicy::in(Online::observed($settings)))->toEqual(Findings::of(Finding::of(
        Slug::ForkApprovalWeak,
        Severity::Advice,
        sprintf('octo/gate runs the workflows of a fork\'s pull request unapproved unless its author is %s.', $who),
        'A fork\'s pull request runs its own code in the gate\'s jobs, and can spend the runners\' minutes.',
        "Require approval for all external contributors, under Settings, Actions, General,\nin the approval for running fork pull request workflows from contributors.",
    )));
})->with([
    'first-time contributors' => [ForkApproval::FirstTimeContributors, 'a first-time contributor'],
    'those new to GitHub' => [ForkApproval::FirstTimeContributorsNewToGitHub, 'a first-time contributor new to GitHub'],
]);

it('finds nothing where every outside contributor is approved, the policy was not read, or nothing was asked', function (): void {
    $unread = Online::settings(Listed::of(), CannotTell::because('403'), Online::ranAt('2026-09-29T03:00:00Z'));

    expect(ForkApprovalPolicy::in(Online::observed(Online::wellSet())))->toEqual(Findings::none())
        ->and(ForkApprovalPolicy::in(Online::observed($unread)))->toEqual(Findings::none())
        ->and(ForkApprovalPolicy::in(Observations::none()))->toEqual(Findings::none());
});
