<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\Check\InfectionUnpatched;
use NightWorksIO\MutationGate\Core\Doctor\ComposerSetup;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Patched;
use NightWorksIO\MutationGate\Core\Doctor\ProjectFiles;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;
use NightWorksIO\MutationGate\Tests\Support\Configs;

/** What the check finds for a project on this runner whose Infection is in this state. */
function infectionUnpatchedIn(string $runner, Patched $patch): Findings
{
    return InfectionUnpatched::in(Observations::none()
        ->withSettings(Configs::settings(['runner' => $runner]))
        ->withFiles(ProjectFiles::none()->withComposer(ComposerSetup::of(Paths::none(), $patch))));
}

it('advises infection:patch where the runner is Infection and its Infection does not carry it', function (): void {
    expect(infectionUnpatchedIn('infection', Patched::Missing))->toEqual(Findings::of(Finding::of(
        Slug::InfectionUnpatched,
        Severity::Advice,
        'The runner is Infection, and the installed Infection does not carry infection:patch.',
        'Infection then gives each mutant its own limit with no floor, so a quick mutant can time out under load.',
        'Add @php vendor/bin/mutation-gate infection:patch to post-install-cmd and post-update-cmd, then install.',
    )));
});

it('finds nothing where Infection carries the patch, is not installed, or is not the runner', function (string $runner, Patched $patch): void {
    expect(infectionUnpatchedIn($runner, $patch))->toEqual(Findings::none());
})->with([
    'patched' => ['infection', Patched::Applied],
    'not installed' => ['infection', Patched::NotInstalled],
    'another runner' => ['pest', Patched::Missing],
    'the PHPUnit runner' => ['phpunit', Patched::Missing],
]);

it('finds nothing where nothing was read', function (): void {
    expect(InfectionUnpatched::in(Observations::none()))->toEqual(Findings::none());
});
