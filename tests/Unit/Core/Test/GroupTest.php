<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Test\Group;

it('holds its name', function (): void {
    expect(Group::named('holds:src/Kernel.php')->name())->toBe('holds:src/Kernel.php');
});
