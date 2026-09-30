<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Hold\Holding;
use NightWorksIO\MutationGate\Core\Test\Group;

it('is a path a group holds, named by the group', function (): void {
    $holding = Holding::byGroup('src/Kernel.php', Group::named('holds:src/Kernel.php'));

    expect($holding->declared())->toBe('src/Kernel.php')
        ->and($holding->by())->toEqual(Group::named('holds:src/Kernel.php'))
        ->and($holding->written())->toBe('holds:src/Kernel.php');
});

it('is a path a #[Holds] holds, named by the attribute and what it stands on', function (): void {
    $holding = Holding::byAttribute('src/Kernel.php', 'Tests\KernelTest::testBoots');

    expect($holding->declared())->toBe('src/Kernel.php')
        ->and($holding->by())->toBe('Tests\KernelTest::testBoots')
        ->and($holding->written())->toBe("#[Holds('src/Kernel.php')] on Tests\\KernelTest::testBoots");
});
