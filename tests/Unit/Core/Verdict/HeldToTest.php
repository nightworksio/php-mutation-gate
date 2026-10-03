<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Verdict\HeldTo;

it('holds the floors a command names, or a pull request\'s trees and new code, and any other run\'s trees', function (): void {
    expect(HeldTo::named(HeldTo::NewCode, pullRequest: true))->toBe(HeldTo::NewCode)
        ->and(HeldTo::named(HeldTo::TreesAndNewCode, pullRequest: false))->toBe(HeldTo::TreesAndNewCode)
        ->and(HeldTo::named(NotGiven::value(), pullRequest: true))->toBe(HeldTo::TreesAndNewCode)
        ->and(HeldTo::named(NotGiven::value(), pullRequest: false))->toBe(HeldTo::Trees);
});

it('holds trees, new code, or both', function (): void {
    expect(array_map(static fn(HeldTo $heldTo): array => [$heldTo->holdsTrees(), $heldTo->holdsNewCode()], HeldTo::cases()))
        ->toBe([[true, false], [true, true], [false, true]]);
});
