<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Doctor\Check\UnsupportedRunner;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\InstalledRunners;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

it('finds the chosen runner installed at releases the gate does not drive, saying which', function (): void {
    $runners = InstalledRunners::of(pest: false, infection: false, chosen: true)
        ->releasing(CannotJudge::because('The phpunit runner needs PHPUnit 13.2.0 or later, and installed.json holds 12.5.42.'));

    expect(UnsupportedRunner::in(Observations::none()->withRunners($runners)))->toEqual(Findings::of(Finding::of(
        Slug::RunnerUnsupported,
        Severity::WillFail,
        'The phpunit runner needs PHPUnit 13.2.0 or later, and installed.json holds 12.5.42.',
        'Every run reads the releases Composer installed first, and refuses those it does not drive.',
        'Require the releases the requirements list for this runner, or choose a runner the installed releases suit.',
    )));
});

it('finds nothing where the gate drives the releases installed, nobody read them, or nothing was observed', function (): void {
    $driven = InstalledRunners::of(pest: false, infection: false, chosen: true)
        ->releasing(Versions::of(Version::of('phpunit/phpunit', '13.4.5', 'a')));

    expect(UnsupportedRunner::in(Observations::none()->withRunners($driven)))->toEqual(Findings::none())
        ->and(UnsupportedRunner::in(Observations::none()->withRunners(InstalledRunners::of(pest: false, infection: false, chosen: true))))
        ->toEqual(Findings::none())
        ->and(UnsupportedRunner::in(Observations::none()))->toEqual(Findings::none());
});
