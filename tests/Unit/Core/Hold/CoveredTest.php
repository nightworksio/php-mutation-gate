<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hold\Covered;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Unit\Unit;

it('is about the held unit its tests may judge, and the tests of the group that run it', function (): void {
    $unit = Unit::held(Path::of('src/Kernel.php'), Group::named('holds:src/Kernel.php'));
    $tests = TestIds::of(TestId::of('KernelTest::boots'));
    $covered = Covered::by($unit, $tests);

    expect($covered->unit())->toBe($unit)
        ->and($covered->tests())->toBe($tests);
});
