<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hold\Covered;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Unit\Unit;

it('is about the held unit its tests may judge', function (): void {
    $unit = Unit::held(Path::of('src/Kernel.php'), Group::named('holds:src/Kernel.php'));

    expect(Covered::by($unit)->unit())->toBe($unit);
});
