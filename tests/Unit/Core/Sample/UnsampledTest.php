<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Sample\Candidate;
use NightWorksIO\MutationGate\Core\Sample\Unsampled;
use NightWorksIO\MutationGate\Core\Unit\Unit;

$left = static fn(string $path, int $mutants): Candidate => Candidate::of(Unit::file(Path::of($path)), $mutants, NotGiven::value());

it('holds the units a sample left out, in the order they were added', function () use ($left): void {
    $unsampled = Unsampled::none()->with($left('src/A.php', 3))->and(Unsampled::none()->with($left('src/B.php', 4)));

    expect(count($unsampled))->toBe(2)
        ->and(array_map(static fn(Candidate $one): int => $one->mutants(), [...$unsampled]))->toBe([3, 4]);
});

it('holds none at first', function (): void {
    expect(count(Unsampled::none()))->toBe(0)
        ->and([...Unsampled::none()])->toBe([]);
});
