<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\Coverage\LoadedPackages;
use NightWorksIO\MutationGate\Core\Coverage\ProcessLoads;
use NightWorksIO\MutationGate\Core\Coverage\RecordedBeside;
use NightWorksIO\MutationGate\Core\Coverage\TestLoad;
use NightWorksIO\MutationGate\Core\Coverage\TimedTest;
use NightWorksIO\MutationGate\Core\File\Path;

it('records nothing to begin with, and adds nothing to a map', function (): void {
    $map = CoverageMap::of(CoveredLine::of(Path::of('src/A.php'), 3, 'T\A::a'))->timedEach(TimedTest::of('T\A::a', 1.0));

    expect(RecordedBeside::nothing()->timed())->toBe([])
        ->and(RecordedBeside::nothing()->loads())->toEqual(LoadedPackages::unrecorded())
        ->and(RecordedBeside::nothing()->onto($map))->toEqual($map);
});

it('times each test the map does not time, the later time of one timed twice, and keeps the map\'s own times', function (): void {
    $loads = LoadedPackages::of(ProcessLoads::of(TestLoad::of('T\A::a', 'vendor/a')));
    $recorded = RecordedBeside::of(
        $loads,
        TimedTest::of('T\A::a', 9.0),
        TimedTest::of('T\B::b', 2.0),
        TimedTest::of('T\B::b', 3.0),
    );
    $map = CoverageMap::of(CoveredLine::of(Path::of('src/A.php'), 3, 'T\A::a'))->timedEach(TimedTest::of('T\A::a', 1.0));

    expect($recorded->timed())->toEqual([TimedTest::of('T\A::a', 9.0), TimedTest::of('T\B::b', 2.0), TimedTest::of('T\B::b', 3.0)])
        ->and($recorded->loads())->toBe($loads)
        ->and($recorded->onto($map))->toEqual($map->timedEach(TimedTest::of('T\B::b', 2.0), TimedTest::of('T\B::b', 3.0))->loading($loads));
});
