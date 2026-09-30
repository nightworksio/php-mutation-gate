<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hold\HeldMisses;
use NightWorksIO\MutationGate\Core\Hold\NotCovered;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Unit\Unit;

$miss = static fn(string $path, string $why): NotCovered => NotCovered::because(
    Unit::held(Path::of($path), Group::holding($path)),
    $why,
);

it('holds each held unit once, by its path, in the order first added', function () use ($miss): void {
    $misses = HeldMisses::none()
        ->with($miss('src/Kernel.php', 'first'))
        ->and(HeldMisses::of($miss('src/Http', 'second'), $miss('src/Kernel.php', 'again')));

    expect(array_map(static fn(NotCovered $each): string => $each->why(), [...$misses]))->toBe(['first', 'second'])
        ->and($misses)->toHaveCount(2)
        ->and($misses->misses(Path::of('src/Http')))->toBeTrue()
        ->and($misses->misses(Path::of('src/Money.php')))->toBeFalse()
        ->and(HeldMisses::none())->toHaveCount(0);
});
