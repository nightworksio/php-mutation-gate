<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Weighed;
use NightWorksIO\MutationGate\Core\Plan\Workload;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;

$weighed = static fn(string $path, string $package): Weighed => Weighed::of(
    Unit::file(Path::of($path)),
    Package::at(Path::of($package)),
    Seconds::of(1.0),
);

it('counts its units', function () use ($weighed): void {
    expect(Workload::of())->toHaveCount(0)
        ->and(Workload::of($weighed('src/A.php', '.'), $weighed('src/B.php', '.')))->toHaveCount(2);
});

it('groups its units by package, packages and units each in path order', function () use ($weighed): void {
    $work = Workload::of(
        $weighed('packages/b/src/B.php', 'packages/b'),
        $weighed('src/Z.php', '.'),
        $weighed('src/A.php', '.'),
        $weighed('packages/a/src/A.php', 'packages/a'),
    );

    $grouped = array_map(
        static fn(array $units): array => array_map(
            static fn(Weighed $unit): string => $unit->unit()->path()->value(),
            $units,
        ),
        $work->byPackage(),
    );

    expect($grouped)->toBe([
        '.' => ['src/A.php', 'src/Z.php'],
        'packages/a' => ['packages/a/src/A.php'],
        'packages/b' => ['packages/b/src/B.php'],
    ]);
});
