<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\CostBasis;
use NightWorksIO\MutationGate\Core\Cost\Estimated;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Batching;
use NightWorksIO\MutationGate\Core\Plan\Weighed;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

/** A file unit that costs this many seconds. */
function batchFile(string $path, float $cost): Weighed
{
    return Weighed::of(Unit::file(Path::of($path)), Package::at(Path::root()), Estimated::of(Seconds::of($cost), CostBasis::Guessed));
}

/** A held unit that costs this many seconds. */
function batchHeld(string $path, float $cost): Weighed
{
    return Weighed::of(Unit::held(Path::of($path), Group::named(sprintf('holds:%s', $path))), Package::at(Path::root()), Estimated::of(Seconds::of($cost), CostBasis::Guessed));
}

$paths = static fn(Units $units): array => array_map(static fn(Unit $unit): string => $unit->path()->value(), [...$units]);
$makeBatching = static fn(): Batching => Batching::opening(Seconds::of(10.0));

it('takes as many units in a row as fit the time left after the opening run', function () use ($makeBatching, $paths): void {
    $batching = $makeBatching();

    $ordered = [batchFile('src/A.php', 5.0), batchFile('src/B.php', 5.0), batchFile('src/C.php', 5.0)];

    expect($paths($batching->next($ordered, Seconds::of(20.0))))->toBe(['src/A.php', 'src/B.php'])
        ->and($paths($batching->next($ordered, Seconds::of(25.0))))->toBe(['src/A.php', 'src/B.php', 'src/C.php']);
});

it('starts nothing where the first unit does not fit', function () use ($makeBatching, $paths): void {
    $batching = $makeBatching();

    expect($paths($batching->next([batchFile('src/A.php', 5.0)], Seconds::of(14.9))))->toBe([])
        ->and($paths($batching->next([], Seconds::of(100.0))))->toBe([]);
});

it('runs a held unit alone, and ends a batch of files where one comes', function () use ($makeBatching, $paths): void {
    $batching = $makeBatching();

    $ordered = [batchHeld('src/Held', 1.0), batchFile('src/A.php', 1.0)];
    $files = [batchFile('src/A.php', 1.0), batchHeld('src/Held', 1.0), batchFile('src/B.php', 1.0)];

    expect($paths($batching->next($ordered, Seconds::of(100.0))))->toBe(['src/Held'])
        ->and($paths($batching->next($files, Seconds::of(100.0))))->toBe(['src/A.php']);
});

it('ends a chunk once the next unit takes it past its size, the opening run counted', function () use ($makeBatching, $paths): void {
    $batching = $makeBatching();

    $ordered = [batchFile('src/A.php', 5.0), batchFile('src/B.php', 5.0), batchFile('src/C.php', 5.0)];
    $chunked = $batching->inChunksOf(Seconds::of(20.0));

    expect($paths($chunked->next($ordered, Unlimited::time())))->toBe(['src/A.php', 'src/B.php'])
        ->and($paths($chunked->next($ordered, Seconds::of(15.0))))->toBe(['src/A.php'])
        ->and($paths($batching->inChunksOf(Seconds::of(25.0))->next($ordered, Unlimited::time())))
        ->toBe(['src/A.php', 'src/B.php', 'src/C.php']);
});

it('starts a chunk with a unit larger than the chunk, alone, where the time left has room for it', function () use ($makeBatching, $paths): void {
    $batching = $makeBatching();

    $ordered = [batchFile('src/Big.php', 300.0), batchFile('src/A.php', 1.0)];
    $chunked = $batching->inChunksOf(Seconds::of(20.0));

    expect($paths($chunked->next($ordered, Unlimited::time())))->toBe(['src/Big.php'])
        ->and($paths($chunked->next($ordered, Seconds::of(309.9))))->toBe([]);
});

it('takes every unit in a row where neither time nor a chunk limits it, a held unit still alone', function () use ($makeBatching, $paths): void {
    $batching = $makeBatching();

    $ordered = [batchFile('src/A.php', 500.0), batchFile('src/B.php', 500.0), batchHeld('src/Held', 1.0)];

    expect($paths($batching->next($ordered, Unlimited::time())))->toBe(['src/A.php', 'src/B.php'])
        ->and($paths($batching->next([batchHeld('src/Held', 1.0), batchFile('src/A.php', 1.0)], Unlimited::time())))
        ->toBe(['src/Held']);
});

it('takes a unit only with room for its kills\' control after the batch\'s mutants, the controls in rounds side by side', function () use ($makeBatching, $paths): void {
    $ordered = array_map(static fn(string $name): Weighed => batchFile(sprintf('src/%s.php', $name), 5.0)->controlledWithin(Seconds::of(10.0)), ['A', 'B', 'C', 'D', 'E']);

    expect($paths($makeBatching()->next($ordered, Seconds::of(50.0))))->toBe(['src/A.php', 'src/B.php'])
        ->and($paths($makeBatching()->controlledAbreast(ProcessCount::of(2))->next($ordered, Seconds::of(50.0))))
        ->toBe(['src/A.php', 'src/B.php', 'src/C.php', 'src/D.php'])
        ->and($paths($makeBatching()->next($ordered, Unlimited::time())))->toBe(['src/A.php', 'src/B.php', 'src/C.php', 'src/D.php', 'src/E.php'])
        ->and($paths($makeBatching()->next([batchFile('src/A.php', 5.0)->controlledWithin(Seconds::of(10.0))], Seconds::of(24.9))))->toBe([]);
});

it('keeps a quick round\'s saved unit and only as many more as leave its kills\' control room, where mutating alone would take all 42', function () use ($paths): void {
    $saved = batchFile('src/Validation/Constraint/IdentifiedBy.php', 2.0);
    $others = array_map(static fn(int $at): Weighed => batchFile(sprintf('src/Other%d.php', $at), 1.0), range(1, 41));
    $controlled = array_map(static fn(Weighed $one): Weighed => $one->controlledWithin(Seconds::of(10.0)), [$saved, ...$others]);
    $batching = Batching::opening(Seconds::of(5.0))->controlledAbreast(ProcessCount::of(2));

    $batch = $paths($batching->next($controlled, Seconds::of(60.0)));

    expect(count($paths($batching->next([$saved, ...$others], Seconds::of(60.0)))))->toBe(42)
        ->and($batch[0])->toBe('src/Validation/Constraint/IdentifiedBy.php')
        ->and(count($batch))->toBe(8);
});
