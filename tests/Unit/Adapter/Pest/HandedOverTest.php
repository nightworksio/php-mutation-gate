<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\HandedOver;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\TestId;

it('covers the lines of a file on disk as the map handed over does, each test once', function (): void {
    $project = Project::at('/p', Paths::of(Path::of('tests')), Path::of('.mutation-gate'), Path::of('vendor'));
    $map = CoverageMap::of()
        ->covered(Path::of('src/Money.php'), Line::of(10), TestId::of('T::a'))
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('T::a'))
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('T::b'))
        ->covered(Path::of('src/Money.php'), Line::of(20), TestId::of('T::c'));
    $handedOver = new HandedOver($map, $project);

    expect($handedOver->testsCovering('/p/src/Money.php', 10, 11))->toBe(['T::a', 'T::b'])
        ->and($handedOver->testsCovering('/p/src/Money.php', 12, 19))->toBe([])
        ->and($handedOver->testsCovering('/p/src/Other.php', 1, 99))->toBe([])
        ->and($handedOver->map($project))->toBe($map);
});
