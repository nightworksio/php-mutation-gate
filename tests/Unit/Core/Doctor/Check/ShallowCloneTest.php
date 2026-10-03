<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\Check\ShallowClone;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\ProjectFiles;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

it('says a shallow clone slows change-scoped runs and carries no kill from an older commit, and how to clone it whole', function (): void {
    expect(ShallowClone::in(Observations::none()->withFiles(ProjectFiles::none()->shallow())))->toEqual(Findings::of(Finding::of(
        Slug::ShallowClone,
        Severity::Slow,
        'The clone is shallow: it holds only the newest commits.',
        "Git cannot say what changed since a commit the clone does not hold, so a change-scoped run from an older base\n"
        . 'mutates everything, and no kill carries from an older commit for a unit a time budget never started.',
        'Clone the whole history, as `fetch-depth: 0` asks of `actions/checkout`.',
    )));
});

it('finds nothing in a clone of the whole history', function (): void {
    expect(ShallowClone::in(Observations::none()->withFiles(ProjectFiles::none())))->toEqual(Findings::none())
        ->and(ShallowClone::in(Observations::none()))->toEqual(Findings::none());
});
