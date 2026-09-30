<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\Check\InfectionImport;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\InfectionConfig;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\ProjectFiles;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

it('advises importing an Infection config that sets a minMsi or ignores mutants itself', function (bool $minMsi, bool $ignores, string $sets): void {
    $config = InfectionConfig::in('infection.json5', minMsi: $minMsi, ignores: $ignores);

    expect(InfectionImport::in(Observations::none()->withFiles(ProjectFiles::none()->withInfection($config))))->toEqual(Findings::of(Finding::of(
        Slug::InfectionConfigToImport,
        Severity::Advice,
        sprintf('infection.json5 %s.', $sets),
        'The gate reads neither: floors replace minMsi, and ignores.entries replaces Infection\'s ignores.',
        'Run mutation-gate init --from=infection.json5, which writes both into a config and says how each key maps.',
    )));
})->with([
    'a minMsi' => [true, false, 'sets a minMsi'],
    'its ignores' => [false, true, 'ignores mutants by Infection\'s own rules'],
    'both' => [true, true, 'sets a minMsi and ignores mutants by Infection\'s own rules'],
]);

it('finds nothing in a config that sets neither, or where there is none', function (): void {
    $plain = InfectionConfig::in('infection.json5', minMsi: false, ignores: false);

    expect(InfectionImport::in(Observations::none()->withFiles(ProjectFiles::none()->withInfection($plain))))->toEqual(Findings::none())
        ->and(InfectionImport::in(Observations::none()))->toEqual(Findings::none());
});
