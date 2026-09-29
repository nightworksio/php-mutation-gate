<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Revision;

it('is a ref as git names it', function (): void {
    $revision = Revision::ref('origin/main');

    expect($revision->name())->toBe('origin/main')
        ->and($revision->isWorkingTree())->toBeFalse();
});

it('is the working tree as it is on disk', function (): void {
    $revision = Revision::workingTree();

    expect($revision->name())->toBe('the working tree')
        ->and($revision->isWorkingTree())->toBeTrue();
});
