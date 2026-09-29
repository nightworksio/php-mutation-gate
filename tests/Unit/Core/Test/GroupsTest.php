<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;

$names = static fn(Groups $groups): array => array_map(static fn(Group $group): string => $group->name(), iterator_to_array($groups, preserve_keys: true));

it('holds nothing to begin with', function (): void {
    expect(Groups::none())->toHaveCount(0);
});

it('keeps each group once, in the order it came', function () use ($names): void {
    $groups = Groups::of(Group::named('slow'), Group::named('2026'), Group::named('slow'));

    expect($names($groups))->toBe(['slow', '2026'])
        ->and($groups)->toHaveCount(2);
});

it('adds a group without changing the groups it came from', function (): void {
    $groups = Groups::none();

    expect($groups->with(Group::named('slow')))->toHaveCount(1)
        ->and($groups)->toHaveCount(0);
});

it('says whether it holds a group', function (): void {
    $groups = Groups::of(Group::named('slow'));

    expect($groups->has(Group::named('slow')))->toBeTrue()
        ->and($groups->has(Group::named('fast')))->toBeFalse();
});
