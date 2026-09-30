<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hold\HotPaths;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

/**
 * A suite of so many tests, each of the files given run by the first so many of them.
 *
 * @param array<string, int> $runs
 */
function suiteRunning(int $tests, array $runs): CoverageMap
{
    $map = CoverageMap::empty();

    for ($test = 1; $test <= $tests; ++$test) {
        $map = $map->timed(TestId::of(sprintf('Test%d', $test)), Seconds::of(0.1));
    }

    foreach ($runs as $file => $running) {
        for ($test = 1; $test <= $running; ++$test) {
            $map = $map->covered(Path::of($file), Line::of(1), TestId::of(sprintf('Test%d', $test)));
        }
    }

    return $map;
}

it('names a file the given share of a suite of twenty tests runs, and no file fewer run', function (): void {
    $map = suiteRunning(20, ['src/Kernel.php' => 16, 'src/Money.php' => 15]);

    expect(HotPaths::standard()->in($map, Units::none()))->toEqual(Warnings::of(Warning::that(
        '`src/Kernel.php` is run by 16 of 20 tests and nothing holds it; each of its mutants runs most of the suite.',
    )));
});

it('names nothing in a suite of fewer than twenty tests', function (): void {
    expect(HotPaths::standard()->in(suiteRunning(19, ['src/Kernel.php' => 19]), Units::none()))->toEqual(Warnings::none());
});

it('names no file a held unit holds, whether it is the unit or inside it', function (): void {
    $map = suiteRunning(20, ['src/Kernel.php' => 20, 'src/Http/Controller.php' => 20, 'src/Money.php' => 20]);
    $units = Units::of(
        Unit::held(Path::of('src/Kernel.php'), Group::named('holds:src/Kernel.php')),
        Unit::held(Path::of('src/Http'), Group::named('holds:src/Http')),
        Unit::file(Path::of('src/Money.php')),
    );

    expect(HotPaths::standard()->in($map, $units))->toEqual(Warnings::of(Warning::that(
        '`src/Money.php` is run by 20 of 20 tests and nothing holds it; each of its mutants runs most of the suite.',
    )));
});

it('takes the share of the suite that makes a file hot', function (): void {
    $map = suiteRunning(40, ['src/Kernel.php' => 20, 'src/Money.php' => 19]);

    expect(HotPaths::atShare(0.5)->in($map, Units::none()))->toEqual(Warnings::of(Warning::that(
        '`src/Kernel.php` is run by 20 of 40 tests and nothing holds it; each of its mutants runs most of the suite.',
    )));
});

it('writes its share as holds.hotPath does', function (): void {
    expect(HotPaths::standard()->share())->toBe(0.8)
        ->and(HotPaths::atShare(0.5)->share())->toBe(0.5);
});
