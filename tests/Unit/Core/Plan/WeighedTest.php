<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Weighed;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;

it('holds a unit, its package and its cost', function (): void {
    $unit = Unit::file(Path::of('src/Money.php'));
    $package = Package::at(Path::root());
    $cost = Seconds::of(12.4);
    $weighed = Weighed::of($unit, $package, $cost);

    expect($weighed->unit())->toBe($unit)
        ->and($weighed->package())->toBe($package)
        ->and($weighed->cost())->toBe($cost);
});
