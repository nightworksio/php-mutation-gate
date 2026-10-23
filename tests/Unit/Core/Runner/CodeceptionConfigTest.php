<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\CodeceptionConfig;

it('names Codeception\'s config and the one it reads beneath it, in a directory', function (): void {
    expect(CodeceptionConfig::candidatesIn(Path::of('packages/billing')))
        ->toEqual(Paths::of(Path::of('packages/billing/codeception.yml'), Path::of('packages/billing/codeception.dist.yml')));
});
