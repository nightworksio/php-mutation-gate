<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\DependentCap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

it('keeps the first 25 dependents in path order', function (): void {
    $files = array_map(static fn(int $n): Path => Path::of(sprintf('src/F%02d.php', $n)), range(30, 1));

    expect([...DependentCap::standard()->of(Paths::of(...$files))])
        ->toEqual(array_map(static fn(int $n): Path => Path::of(sprintf('src/F%02d.php', $n)), range(1, 25)));
});

it('keeps every dependent within the cap, in path order', function (): void {
    expect([...DependentCap::standard()->of(Paths::of(Path::of('tests/MoneyTest.php'), Path::of('src/Wallet.php')))])
        ->toEqual([Path::of('src/Wallet.php'), Path::of('tests/MoneyTest.php')])
        ->and(DependentCap::standard()->of(Paths::none())->count())->toBe(0);
});
