<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Ci\GatePin;
use NightWorksIO\MutationGate\Core\Doctor\Check\WorkflowPin;
use NightWorksIO\MutationGate\Core\Doctor\DoctorRun;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\GateDefinitions;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\ProjectFiles;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

$installed = static fn(): GatePin => GatePin::of(Version::of('nightworksio/mutation-gate', 'v0.4.0', str_repeat('b', 40)));
$observed = static fn(GateDefinitions $running, GatePin $pin): Observations => Observations::none()
    ->in(DoctorRun::of(new DateTimeImmutable('2026-11-05'), -1)->pinning($pin))
    ->withFiles(ProjectFiles::none()->withRunningTheGate($running));

it('advises pinning the installed release in each CI definition that pins another', function () use ($installed, $observed): void {
    $stale = sprintf('nightworksio/php-mutation-gate@%s', str_repeat('a', 40));
    $running = GateDefinitions::none()
        ->with(Path::of('.github/workflows/mutation.yml'), sprintf("      - uses: %s\n", $stale))
        ->with(Path::of('.gitlab-ci.yml'), "    image: ghcr.io/nightworksio/mutation-gate:0.4.0\n");

    expect(WorkflowPin::in($observed($running, $installed())))->toEqual(Findings::of(Finding::of(
        Slug::WorkflowPinStale,
        Severity::Advice,
        sprintf('.github/workflows/mutation.yml pins %s, and the project installed v0.4.0.', $stale),
        'CI runs the release a definition pins, so it judges with a gate other than the one installed here.',
        sprintf('Pin %s # v0.4.0 in .github/workflows/mutation.yml, as mutation-gate doctor --fix does.', str_repeat('b', 40)),
    )));
});

it('finds nothing where no definition runs the gate, nothing says which release runs, or no release is known', function () use ($installed, $observed): void {
    $running = GateDefinitions::none()->with(Path::of('.gitlab-ci.yml'), "    image: ghcr.io/nightworksio/mutation-gate:0.3.0\n");

    expect(WorkflowPin::in($observed(GateDefinitions::none(), $installed())))->toEqual(Findings::none())
        ->and(WorkflowPin::in(Observations::none()->withFiles(ProjectFiles::none()->withRunningTheGate($running))))->toEqual(Findings::none())
        ->and(WorkflowPin::in(Observations::none()->in(DoctorRun::of(new DateTimeImmutable('2026-11-05'), -1))))->toEqual(Findings::none())
        ->and(WorkflowPin::in($observed($running, GatePin::unknown())))->toEqual(Findings::none());
});
