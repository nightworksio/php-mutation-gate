<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Control\MutantParts;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Reach\TestPlaces;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\RunTests;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * A map that times each of these tests so, by its id.
 *
 * @param array<string, float> $times
 */
function partsMap(array $times): CoverageMap
{
    $map = CoverageMap::empty();

    foreach ($times as $test => $seconds) {
        $map = $map->timed(TestId::of($test), Seconds::of($seconds));
    }

    return $map;
}

/** The tests of these ids. */
function partsTests(string ...$ids): TestIds
{
    return TestIds::of(...array_map(TestId::of(...), $ids));
}

/**
 * Each part as the ids of its tests.
 *
 * @param  list<RunTests>       $parts
 * @return list<list<string>>
 */
function partsIds(array $parts): array
{
    return array_map(
        static fn(RunTests $part): array => array_map(static fn(TestId $test): string => $test->value(), [...$part->tests()]),
        $parts,
    );
}

/** Bounds of a most of six seconds and a start-up of a tenth, under which tests of 1.9 seconds fit in one part. */
function partsBounds(): LimitBounds
{
    return LimitBounds::between(Seconds::of(1.0), Seconds::of(6.0))->startingIn(Seconds::of(0.1));
}

it('runs the tests of the file nearest the mutant\'s first, a file\'s together where they fit, and those in no file last', function (): void {
    $map = partsMap(['n1' => 0.5, 'n2' => 0.5, 'f1' => 0.2, 'f2' => 1.5, 'u1' => 0.1]);
    $places = TestPlaces::none()
        ->placing(Path::of('tests/Feature/WholeTest.php'), partsTests('f1', 'f2'))
        ->placing(Path::of('app-modules/money/tests/MoneyTest.php'), partsTests('n1', 'n2'));
    $tests = partsTests('f1', 'u1', 'n1', 'f2', 'n2');

    expect(partsIds(MutantParts::under($map, partsBounds())->of(Path::of('app-modules/money/src/Money.php'), $tests, $places)))
        ->toBe([['n1', 'n2'], ['f1', 'f2', 'u1']])
        ->and(MutantParts::under($map, partsBounds())->overflow($tests))->toBeTrue()
        ->and(MutantParts::under($map, partsBounds())->overflow(partsTests('n1', 'n2')))->toBeFalse();
});

it('splits a file whose tests do not fit together one test at a time, the fastest first, and gives a test too slow for any part one of its own', function (): void {
    $map = partsMap(['z1' => 1.5, 'z2' => 1.2, 'z3' => 0.3, 'w' => 2.5]);
    $places = TestPlaces::none()->placing(Path::of('tests/ZTest.php'), partsTests('z1', 'z2', 'z3', 'w'));

    expect(partsIds(MutantParts::under($map, partsBounds())->of(Path::of('src/Z.php'), partsTests('z1', 'z2', 'z3', 'w'), $places)))
        ->toBe([['z3', 'z2'], ['z1'], ['w']]);
});

it('orders files that share as many directories with the mutant\'s by their own time, then by their path', function (): void {
    $map = partsMap(['a' => 1.0, 'b' => 0.8, 'c' => 0.8]);
    $places = TestPlaces::none()
        ->placing(Path::of('tests/ATest.php'), partsTests('a'))
        ->placing(Path::of('tests/CTest.php'), partsTests('c'))
        ->placing(Path::of('tests/BTest.php'), partsTests('b'));

    expect(partsIds(MutantParts::under($map, partsBounds())->of(Path::of('src/X.php'), partsTests('a', 'b', 'c'), $places)))
        ->toBe([['b', 'c'], ['a']]);
});
