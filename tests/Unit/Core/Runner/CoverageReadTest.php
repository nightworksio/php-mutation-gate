<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;

it('reads the map another job left in a directory', function (): void {
    expect(CoverageRead::from(Path::of('build/coverage'))->directory())->toEqual(Path::of('build/coverage'));
});
