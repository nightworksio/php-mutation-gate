<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hold\NotCovered;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Unit\Unit;

it('is about the held unit its tests cannot judge, and says why', function (): void {
    $unit = Unit::held(Path::of('src/Kernel.php'), Group::named('holds:src/Kernel.php'));
    $notCovered = NotCovered::because($unit, 'The group misses line 12.');

    expect($notCovered->unit())->toBe($unit)
        ->and($notCovered->why())->toBe('The group misses line 12.');
});
