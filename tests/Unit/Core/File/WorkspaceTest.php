<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Workspace;

it('keeps the gate\'s own files under .mutation-gate', function (): void {
    expect(Workspace::root())->toEqual(Path::of('.mutation-gate'));
});
