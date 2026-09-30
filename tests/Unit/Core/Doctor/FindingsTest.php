<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

$finding = static fn(Slug $slug, Severity $severity): Finding => Finding::of($slug, $severity, $slug->value, 'why', 'fix');

it('orders what would fail a run first, then what slows it, then advice, each in the order found', function () use ($finding): void {
    $findings = Findings::of($finding(Slug::WorkspaceNotIgnored, Severity::Advice))->and(
        Findings::of($finding(Slug::XdebugSlowsTests, Severity::Slow), $finding(Slug::NoTree, Severity::WillFail)),
        Findings::of($finding(Slug::TwoRunners, Severity::WillFail), $finding(Slug::InfectionConfigToImport, Severity::Advice)),
    );

    expect(array_map(static fn(Finding $each): Slug => $each->slug(), [...$findings]))->toBe([
        Slug::NoTree,
        Slug::TwoRunners,
        Slug::XdebugSlowsTests,
        Slug::WorkspaceNotIgnored,
        Slug::InfectionConfigToImport,
    ])->and(count($findings->thatAre(Severity::WillFail)))->toBe(2)
        ->and($findings->failARun())->toBeTrue();
});

it('fails no run where nothing would', function () use ($finding): void {
    expect(Findings::none()->failARun())->toBeFalse()
        ->and(count(Findings::none()))->toBe(0)
        ->and(Findings::of($finding(Slug::XdebugSlowsTests, Severity::Slow))->failARun())->toBeFalse();
});
