<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\ArtifactService;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads the token and the URL the action handed in once, and removes the file', function (): void {
    $file = sprintf('%s/service.json', Scratch::directory());
    file_put_contents($file, '{"token": "runtime-token", "url": "https://results.example/"}');
    $service = ArtifactService::handedIn(Variables::of([ArtifactService::FILE => $file]));

    expect($service instanceof ArtifactService ? [$service->token(), $service->url()] : $service)
        ->toBe(['runtime-token', 'https://results.example/'])
        ->and(file_exists($file))->toBeFalse()
        ->and(ArtifactService::handedIn(Variables::of([ArtifactService::FILE => $file])))
        ->toEqual(CannotJudge::because(sprintf('The artifact service is not reachable from this step: %s holds no token and URL.', $file)));
});

it('removes a handoff it cannot read, and reaches nothing where no file is named', function (): void {
    $file = sprintf('%s/service.json', Scratch::directory());
    file_put_contents($file, '{"token": ""}');

    expect(ArtifactService::handedIn(Variables::of([ArtifactService::FILE => $file])))->toBeInstanceOf(CannotJudge::class)
        ->and(file_exists($file))->toBeFalse()
        ->and(ArtifactService::handedIn(Variables::of([])))
        ->toEqual(CannotJudge::because('The artifact service is not reachable from this step: MUTATION_GATE_ARTIFACT_SERVICE names no file.'));
});
