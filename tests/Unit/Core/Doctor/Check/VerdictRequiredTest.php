<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Doctor\Asked;
use NightWorksIO\MutationGate\Core\Doctor\Check\VerdictRequired;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\GitHub\ForkApproval;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Online;

it('advises where the default branch does not require the verdict\'s check-run', function (): void {
    $settings = Online::settings(Listed::of('build'), ForkApproval::AllExternalContributors, Online::ranAt('2026-09-29T03:00:00Z'));

    expect(VerdictRequired::in(Online::observed($settings)))->toEqual(Findings::of(Finding::of(
        Slug::VerdictNotRequired,
        Severity::Advice,
        'octo/gate\'s default branch, main, does not require the check mutation / verdict.',
        'A pull request whose verdict failed can still be merged.',
        'Require the status check mutation / verdict on main, in a ruleset or a branch protection rule.',
    )));
});

it('reads the check-run ci.check names', function (): void {
    $observed = Observations::none()
        ->withSettings(Configs::settings(['runner' => 'pest', 'ci' => ['check' => 'gate']]))
        ->withAsked(Asked::nothing()->withGitHub(Online::wellSet()));

    expect([...VerdictRequired::in($observed)])->toHaveCount(1);
});

it('finds nothing where the verdict is required, the checks were not read, or nothing was asked', function (): void {
    $unread = Online::settings(CannotTell::because('403'), ForkApproval::AllExternalContributors, CannotTell::because('403'));

    expect(VerdictRequired::in(Online::observed(Online::wellSet())))->toEqual(Findings::none())
        ->and(VerdictRequired::in(Online::observed($unread)))->toEqual(Findings::none())
        ->and(VerdictRequired::in(Observations::none()))->toEqual(Findings::none());
});
