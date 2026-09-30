<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\DoctorText;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Troubleshooting\Guide;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

const DOCTOR_GUIDE = 'https://github.com/nightworksio/php-mutation-gate/blob/main/.docs/guide/troubleshooting.md';

$findings = static fn(): Findings => Findings::none()->and(Findings::of(
    Finding::of(Slug::WorkspaceNotIgnored, Severity::Advice, 'Not ignored.', 'It holds runs.', 'Add it.'),
    Finding::of(Slug::XdebugSlowsTests, Severity::Slow, 'Xdebug in develop.', 'It slows tests.', 'Set coverage.')
        ->costing(Seconds::of(90.0)),
    Finding::of(Slug::NoTree, Severity::WillFail, 'No tree.', 'Nothing to mutate.', 'Name one.'),
));

it('writes each finding with why, the fix and its link, and counts them', function () use ($findings): void {
    expect(DoctorText::of($findings(), Guide::unreleased()))->toBe(implode("\n", [
        'will fail  no-tree',
        '  No tree.',
        '  Nothing to mutate.',
        '  Fix: Name one.',
        sprintf('  %s#no-tree', DOCTOR_GUIDE),
        '',
        'slow (2m at stake)  xdebug-slows-tests',
        '  Xdebug in develop.',
        '  It slows tests.',
        '  Fix: Set coverage.',
        sprintf('  %s#xdebug-slows-tests', DOCTOR_GUIDE),
        '',
        'advice  workspace-not-ignored',
        '  Not ignored.',
        '  It holds runs.',
        '  Fix: Add it.',
        sprintf('  %s#workspace-not-ignored', DOCTOR_GUIDE),
        '',
        '1 would fail a run, 1 would slow it, and 1 is advice.',
    ]));
});

it('says so where nothing was found, and counts advice in the plural', function (): void {
    $advice = Finding::of(Slug::WorkspaceNotIgnored, Severity::Advice, 'Not ignored.', 'It holds runs.', 'Add it.');

    expect(DoctorText::of(Findings::none(), Guide::unreleased()))->toBe('Nothing would fail or slow a run, and there is no advice.')
        ->and(DoctorText::of(Findings::of($advice, $advice), Guide::unreleased()))->toEndWith('0 would fail a run, 0 would slow it, and 2 are advice.');
});

it('briefs one line for each finding that fails or slows a run, and none for advice', function () use ($findings): void {
    expect(DoctorText::brief($findings(), Guide::unreleased()))->toBe(implode("\n", [
        sprintf('will fail: No tree. %s#no-tree', DOCTOR_GUIDE),
        sprintf('slow (2m at stake): Xdebug in develop. %s#xdebug-slows-tests', DOCTOR_GUIDE),
    ]))->and(DoctorText::brief(Findings::none(), Guide::unreleased()))->toBe('');
});
