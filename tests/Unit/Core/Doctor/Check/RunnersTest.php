<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\Check\Runners;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\InstalledRunners;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

it('finds both runners installed and nothing choosing between them', function (): void {
    $open = Observations::none()->withRunners(InstalledRunners::of(pest: true, infection: true, chosen: false));

    expect(Runners::in($open))->toEqual(Findings::of(Finding::of(
        Slug::TwoRunners,
        Severity::WillFail,
        'Both Pest\'s mutation plugin and Infection are installed, and nothing chooses between them.',
        'Zero-config chooses the runner from what is installed, so with both it cannot, and no command can run.',
        'Set runner: pest or runner: infection in the config, or pass --runner=pest or --runner=infection.',
    )));
});

it('finds nothing where a runner is chosen, one is installed, or nothing was observed', function (): void {
    expect(Runners::in(Observations::none()->withRunners(InstalledRunners::of(pest: true, infection: true, chosen: true))))
        ->toEqual(Findings::none())
        ->and(Runners::in(Observations::none()->withRunners(InstalledRunners::of(pest: true, infection: false, chosen: false))))
        ->toEqual(Findings::none())
        ->and(Runners::in(Observations::none()))->toEqual(Findings::none());
});
