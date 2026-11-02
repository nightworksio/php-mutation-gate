<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\ServiceFiles;
use NightWorksIO\MutationGate\Core\Doctor\SonarSources;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Owner\CodeOwners;

it('gives no sonar.sources and no CODEOWNERS it was not given', function (): void {
    expect(ServiceFiles::none()->sonarSources())->toEqual(NotGiven::value())
        ->and(ServiceFiles::none()->codeOwners())->toEqual(CodeOwners::none());
});

it('keeps each file it is given, whatever order they come in', function (): void {
    $sonar = SonarSources::of(Path::of('src'));
    $owners = CodeOwners::read(Path::of('CODEOWNERS'), "* @acme/web\n");
    $services = ServiceFiles::none()->withCodeOwners($owners)->withSonarSources($sonar);

    expect($services->sonarSources())->toBe($sonar)
        ->and($services->codeOwners())->toBe($owners)
        ->and(ServiceFiles::none()->withSonarSources($sonar)->withCodeOwners($owners)->sonarSources())->toBe($sonar);
});
