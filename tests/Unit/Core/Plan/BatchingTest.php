<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\CostBasis;
use NightWorksIO\MutationGate\Core\Cost\Estimated;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Batching;
use NightWorksIO\MutationGate\Core\Plan\Weighed;
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

it('chunks a doomed run\'s shard in about two minutes of expected work', function (): void {
    expect(Batching::chunk())->toEqual(Seconds::minutes(2));
});
