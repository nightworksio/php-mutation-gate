<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Migration\KeyPath;
use NightWorksIO\MutationGate\Core\NotGiven;

it('spells a path by its keys from the top, and names its key and the object that holds it', function (): void {
    $path = KeyPath::of('reach.everything');
    $parent = $path->parent();

    expect([...$path])->toBe(['reach', 'everything'])
        ->and($path->value())->toBe('reach.everything')
        ->and($path->key())->toBe('everything')
        ->and($parent instanceof KeyPath ? $parent->value() : $parent)->toBe('reach')
        ->and(KeyPath::of('runner')->parent())->toEqual(NotGiven::value());
});

it('stands beside a path under the same parent, the top among them', function (): void {
    expect(KeyPath::of('reach.everything')->isBeside(KeyPath::of('reach.all')))->toBeTrue()
        ->and(KeyPath::of('runner')->isBeside(KeyPath::of('preset')))->toBeTrue()
        ->and(KeyPath::of('reach.everything')->isBeside(KeyPath::of('scope.everything')))->toBeFalse()
        ->and(KeyPath::of('reach.everything')->isBeside(KeyPath::of('everything')))->toBeFalse();
});
