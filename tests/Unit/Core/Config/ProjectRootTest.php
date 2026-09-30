<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\File\Path;

it('names paths from the project, and writes them as they are', function (): void {
    expect(ProjectRoot::origin()->path(Path::of('./src/../lib')))->toEqual(Path::of('lib'))
        ->and(ProjectRoot::origin()->written(Path::of('src')))->toBe('src');
});

it('reaches outside the project on the command line only', function (): void {
    expect(ProjectRoot::commandLine()->reachesOutside())->toBeTrue()
        ->and(ProjectRoot::origin()->reachesOutside())->toBeFalse();
});
