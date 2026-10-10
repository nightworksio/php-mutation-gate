<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Doctor\Check\PestUnpatched;
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

/** What the check finds for a project with these settings whose pest-plugin-mutate is in this state. */
function pestUnpatchedIn(Settings $settings, Patched $patch): Findings
{
    return PestUnpatched::in(Observations::none()
        ->withSettings($settings)
        ->withFiles(ProjectFiles::none()->withComposer(ComposerSetup::of(Paths::none(), pest: $patch))));
}

it('fails a run where pest.patch is on and the installed plugin does not carry pest:patch', function (): void {
    expect(pestUnpatchedIn(Configs::settings(['runner' => 'pest', 'pest' => ['patch' => true]]), Patched::Missing))->toEqual(Findings::of(Finding::of(
        Slug::PestUnpatched,
        Severity::WillFail,
        'pest.patch is on, and the installed pest-plugin-mutate does not carry pest:patch.',
        'A patched shard opens on the canary group, and refuses to where the plugin is not patched.',
        'Add @php vendor/bin/mutation-gate pest:patch to post-install-cmd and post-update-cmd, then install.',
    )));
});

it('finds nothing where the plugin carries the patch, is not installed, pest.patch is off, or Pest is not the runner', function (Settings $settings, Patched $patch): void {
    expect(pestUnpatchedIn($settings, $patch))->toEqual(Findings::none());
})->with([
    'patched' => [fn(): Settings => Configs::settings(['runner' => 'pest', 'pest' => ['patch' => true]]), Patched::Applied],
    'not installed' => [fn(): Settings => Configs::settings(['runner' => 'pest', 'pest' => ['patch' => true]]), Patched::NotInstalled],
    'pest.patch off' => [fn(): Settings => Configs::settings(['runner' => 'pest']), Patched::Missing],
    'another runner' => [fn(): Settings => Configs::settings(['runner' => 'infection', 'pest' => ['patch' => true]]), Patched::Missing],
]);

it('finds nothing where nothing was read', function (): void {
    expect(PestUnpatched::in(Observations::none()))->toEqual(Findings::none());
});
