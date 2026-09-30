<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Test\Group;

it('holds its name', function (): void {
    expect(Group::named('holds:src/Kernel.php')->name())->toBe('holds:src/Kernel.php');
});

it('names the group of the tests that hold a path', function (): void {
    expect(Group::holding('src/Kernel.php'))->toEqual(Group::named('holds:src/Kernel.php'));
});

it('knows a holding group, and the path its tests hold', function (): void {
    expect(Group::named('holds:src/Kernel.php')->isHolding())->toBeTrue()
        ->and(Group::named('holds:src/Kernel.php')->held())->toBe('src/Kernel.php')
        ->and(Group::named('slow')->isHolding())->toBeFalse()
        ->and(Group::named('fast-holds:x')->isHolding())->toBeFalse();
});
