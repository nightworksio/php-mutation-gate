<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\CostBasis;
use NightWorksIO\MutationGate\Core\Cost\Estimated;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Weighed;
use NightWorksIO\MutationGate\Core\Test\TestPart;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;

it('holds a unit, its package and its cost', function (): void {
    $unit = Unit::file(Path::of('src/Money.php'));
    $package = Package::at(Path::root());
    $cost = Seconds::of(12.4);
    $weighed = Weighed::of($unit, $package, Estimated::of($cost, CostBasis::Guessed));

    expect($weighed->unit())->toBe($unit)
        ->and($weighed->package())->toBe($package)
        ->and($weighed->cost())->toBe($cost);
});

it('weighs its unit at an alike share of each part of the test files, on the same basis', function (): void {
    $weighed = Weighed::of(Unit::file(Path::of('src/Money.php')), Package::at(Path::root()), Estimated::of(Seconds::of(12.0), CostBasis::Learned));
    $shared = $weighed->sharedAmong(TestPart::of(1, 4));

    expect($shared->cost())->toEqual(Seconds::of(3.0))
        ->and($shared->estimated()->basis())->toBe(CostBasis::Learned)
        ->and($shared->unit())->toBe($weighed->unit())
        ->and($weighed->sharedAmong(TestPart::whole())->cost())->toEqual(Seconds::of(12.0));
});
