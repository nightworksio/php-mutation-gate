<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Hold\Covered;
use NightWorksIO\MutationGate\Core\Hold\MeasuredHolds;
use NightWorksIO\MutationGate\Core\Hold\NotCovered;
use NightWorksIO\MutationGate\Core\Plan\MeasuredHoldsRecord;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Unit\Unit;

it('reads back what the plan measured of each held unit: its covering tests, its misses, or why its tests cannot run alone', function (): void {
    $covered = Unit::file(Path::of('src/A.php'));
    $missed = Unit::file(Path::of('src/B.php'));
    $failed = Unit::file(Path::of('src/C.php'));
    $measured = MeasuredHolds::none()
        ->with($covered, Covered::by($covered, TestIds::of(TestId::of('ATest::runs'))))
        ->with($missed, NotCovered::because($missed, 'Lines 3 and 4 are missed.'))
        ->with($failed, CannotJudge::because('The group cannot run alone.'));
    $written = MeasuredHoldsRecord::of($measured);

    expect($written)->toBe([
        'src/A.php' => ['tests' => ['ATest::runs']],
        'src/B.php' => ['misses' => 'Lines 3 and 4 are missed.'],
        'src/C.php' => ['failed' => 'The group cannot run alone.'],
    ])
        ->and(MeasuredHoldsRecord::read(Node::decode((string) json_encode($written))))->toEqual($measured);
});
