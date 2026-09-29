<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Tree\Package;

it('is the project at a path', function (): void {
    expect(Package::at(Path::of('packages/money'))->path()->value())->toBe('packages/money');
});
