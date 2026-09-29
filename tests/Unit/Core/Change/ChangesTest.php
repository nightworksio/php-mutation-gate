<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\File\Path;

$paths = static fn(Changes $changes): array => array_map(static fn(Change $change): string => $change->path()->value(), iterator_to_array($changes, preserve_keys: true));

it('holds nothing to begin with', function (): void {
    expect(Changes::none())->toHaveCount(0);
});

it('keeps changes in the order they were listed, numbered from nought', function () use ($paths): void {
    expect($paths(Changes::of(...['b' => Change::deleted(Path::of('b')), 'a' => Change::deleted(Path::of('a'))])))->toBe(['b', 'a']);
});

it('adds a change without changing the changes it came from', function () use ($paths): void {
    $changes = Changes::of(Change::deleted(Path::of('a')));

    expect($paths($changes->with(Change::deleted(Path::of('b')))))->toBe(['a', 'b'])
        ->and($changes)->toHaveCount(1);
});
