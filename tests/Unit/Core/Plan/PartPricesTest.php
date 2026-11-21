<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\CostBasis;
use NightWorksIO\MutationGate\Core\Cost\Estimated;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\PartPrices;
use NightWorksIO\MutationGate\Core\Plan\Weighed;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestPart;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;

$weighed = static fn(string $path, float $cost): Weighed => Weighed::of(
    Unit::file(Path::of($path)),
    Package::at(Path::root()),
    Estimated::of(Seconds::of($cost), CostBasis::Learned),
);

$map = static fn(): CoverageMap => CoverageMap::of()
    ->covered(Path::of('src/A.php'), Line::of(3), TestId::of('ATest::one'), TestId::of('BTest::two'))
    ->covered(Path::of('src/B.php'), Line::of(5), TestId::of('BTest::one'), TestId::of('BTest::two'))
    ->covered(Path::of('src/D.php'), Line::of(7), TestId::of('DTest::untimed'))
    ->timed(TestId::of('ATest::one'), Seconds::of(3.0))
    ->timed(TestId::of('BTest::one'), Seconds::of(2.0))
    ->timed(TestId::of('BTest::two'), Seconds::of(1.0));

$costs = static fn(PartPrices $prices, Weighed $unit, int $parts): array => array_map(
    static fn(int $number): float => $prices->weighed($unit, TestPart::of($number, $parts))->cost()->seconds(),
    range(1, $parts),
);

it('prices a unit in each part at its share of its covering tests\' seconds in that part\'s test files', function () use ($weighed, $map, $costs): void {
    $units = [$weighed('src/A.php', 8.0), $weighed('src/B.php', 6.0), $weighed('src/C.php', 4.0)];
    $prices = PartPrices::of($map(), 2, ...$units);

    expect($costs($prices, $units[0], 2))->toBe([6.0, 2.0])
        ->and($costs($prices, $units[1], 2))->toBe([0.0, 6.0])
        ->and($prices->weighed($units[0], TestPart::of(2, 2))->estimated()->basis())->toBe(CostBasis::Learned);
});

it('prices a unit no timed test covers alike in every part', function () use ($weighed, $map, $costs): void {
    $uncovered = $weighed('src/C.php', 4.0);
    $untimed = $weighed('src/D.php', 4.0);
    $prices = PartPrices::of($map(), 2, $uncovered, $untimed);

    expect($costs($prices, $uncovered, 2))->toBe([2.0, 2.0])
        ->and($costs($prices, $untimed, 2))->toBe([2.0, 2.0])
        ->and($costs(PartPrices::of($map(), 2), $weighed('src/A.php', 8.0), 2))->toBe([4.0, 4.0]);
});

it('prices every unit at its whole cost over the parts, summed across them', function () use ($weighed, $map, $costs): void {
    $units = [$weighed('src/A.php', 8.0), $weighed('src/B.php', 6.0), $weighed('src/C.php', 4.0)];

    foreach ([1, 2, 3, 5] as $parts) {
        $prices = PartPrices::of($map(), $parts, ...$units);

        foreach ($units as $unit) {
            expect(array_sum($costs($prices, $unit, $parts)))->toEqualWithDelta($unit->cost()->seconds(), 1e-9);
        }
    }
});

it('prices alike where it knows no cut, or the part is of another cut', function () use ($weighed, $map, $costs): void {
    $unit = $weighed('src/A.php', 8.0);

    expect($costs(PartPrices::alike(), $unit, 4))->toBe([2.0, 2.0, 2.0, 2.0])
        ->and($costs(PartPrices::of($map(), 2, $unit), $unit, 4))->toBe([2.0, 2.0, 2.0, 2.0])
        ->and($costs(PartPrices::of($map(), 1, $unit), $unit, 1))->toBe([8.0]);
});
