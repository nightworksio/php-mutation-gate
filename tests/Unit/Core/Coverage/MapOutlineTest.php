<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\Coverage\LineTests;
use NightWorksIO\MutationGate\Core\Coverage\MapOutline;
use NightWorksIO\MutationGate\Core\Coverage\TimedTest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/** A map of three tests: A runs src/A.php and src/B.php, B runs src/B.php, C is only timed, and no test runs src/C.php. */
function outlinedMap(): CoverageMap
{
    return CoverageMap::of(
        CoveredLine::of(Path::of('src/B.php'), 3, 'T\B::b', 'T\A::a'),
        CoveredLine::of(Path::of('src/A.php'), 5, 'T\A::a'),
        CoveredLine::of(Path::of('src/C.php'), 1),
    )->timedEach(TimedTest::of('T\C::c', 1.0));
}

it('outlines a map: its tests in its order, the files each ran a line of, and each file a test ran by its path', function (): void {
    $outline = MapOutline::of(outlinedMap());

    expect($outline->tests())->toEqual(outlinedMap()->tests())
        ->and($outline->ran(TestId::of('T\A::a')))->toEqual(Paths::of(Path::of('src/B.php'), Path::of('src/A.php')))
        ->and($outline->ran(TestId::of('T\C::c')))->toEqual(Paths::none())
        ->and($outline->ranBy(TestIds::of(TestId::of('T\B::b'), TestId::of('T\A::a'), TestId::of('T\C::c'))))
        ->toEqual(Paths::of(Path::of('src/B.php'), Path::of('src/A.php')))
        ->and($outline->named())->toEqual([
            'src/A.php' => Path::of('src/A.php'),
            'src/B.php' => Path::of('src/B.php'),
        ])
        ->and($outline->map())->toEqual(CoverageMap::placed(LineTests::placed(outlinedMap()->tests())));
});

it('keeps an engine\'s outline as it listed it', function (): void {
    $a = Path::of('src/A.php');
    $outline = MapOutline::listed(TestIds::of(TestId::of('T\A::a')), ['T\A::a' => ['src/A.php' => $a]], ['/p/src/A.php' => $a]);

    expect($outline->tests())->toEqual(TestIds::of(TestId::of('T\A::a')))
        ->and($outline->ran(TestId::of('T\A::a')))->toEqual(Paths::of($a))
        ->and($outline->named())->toBe(['/p/src/A.php' => $a]);
});

it('leaves each test leaving on only the files it stays on, gone where it stays on none, and every other test as it was', function (): void {
    $outline = MapOutline::of(outlinedMap())->leaving([
        'T\A::a' => ['src/A.php' => true, 'src/Elsewhere.php' => true],
        'T\B::b' => [],
        'T\C::c' => ['src/A.php' => true],
    ]);

    expect($outline->tests())->toEqual(TestIds::of(TestId::of('T\A::a')))
        ->and($outline->ran(TestId::of('T\A::a')))->toEqual(Paths::of(Path::of('src/A.php')))
        ->and($outline->ran(TestId::of('T\B::b')))->toEqual(Paths::none())
        ->and($outline->named())->toEqual(MapOutline::of(outlinedMap())->named())
        ->and(MapOutline::of(outlinedMap())->leaving([]))->toEqual(MapOutline::of(outlinedMap()));
});
