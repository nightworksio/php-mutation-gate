<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\TestsDirectory;

it('keeps a project\'s tests under tests when nothing says otherwise', function (): void {
    expect(TestsDirectory::conventional())->toEqual(Path::of('tests'));
});
