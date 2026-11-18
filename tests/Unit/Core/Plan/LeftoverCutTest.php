<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Plan\LeftMutant;
use NightWorksIO\MutationGate\Core\Plan\LeftoverCut;
use NightWorksIO\MutationGate\Core\Plan\LeftoverShare;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/** The id of the mutant on this line of src/Money.php. */
function leftoverCutId(int $line): MutantId
{
    return MutantId::hash(Path::of('src/Money.php'), 'Minus', '- a + b', $line);
}

/** The mutant on this line of src/Money.php, as a share lists it. */
function leftoverCutMutant(int $line): LeftMutant
{
    return LeftMutant::of(leftoverCutId($line), sprintf('Minus-%d', $line), Path::of('src/Money.php'));
}

/**
 * Each share of a shard as its number and the lines of its mutants.
 *
 * @param  list<LeftoverShare> $shares
 * @return list<string>
 */
function leftoverCutShares(array $shares): array
{
    $lines = [];

    foreach (range(1, 40) as $line) {
        $lines[leftoverCutId($line)->value()] = $line;
    }

    return array_map(static fn(LeftoverShare $share): string => sprintf(
        '%d.%d: %s',
        $share->shard()->number(),
        $share->number(),
        implode(' ', array_map(static fn(MutantId $id): int => $lines[$id->value()], [...$share->mutants()])),
    ), $shares);
}

it('cuts each shard\'s left mutants into as many shares as their seconds fill jobs, costliest first to the share that ends first', function (): void {
    $cut = LeftoverCut::of(Digest::sha256Of('plan'), [
        3 => [[leftoverCutMutant(1), 10.0], [leftoverCutMutant(2), 50.0], [leftoverCutMutant(3), 30.0], [leftoverCutMutant(4), 25.0], [leftoverCutMutant(5), 5.0]],
        7 => [[leftoverCutMutant(6), 1.0], [leftoverCutMutant(7), 2.0]],
        9 => [],
    ], [3 => Seconds::of(60.0), 7 => Seconds::of(60.0), 9 => Seconds::of(60.0)]);

    expect(leftoverCutShares($cut->ofShard(ShardId::of(3))))->toBe(['3.1: 2 1', '3.2: 3 4 5'])
        ->and(leftoverCutShares($cut->ofShard(ShardId::of(7))))->toBe(['7.1: 7 6'])
        ->and($cut->ofShard(ShardId::of(9)))->toBe([])
        ->and($cut->cutFrom(Digest::sha256Of('plan')))->toBeTrue();
});

it('gives a mutant no more than a share of its own, however long it is predicted to take', function (): void {
    $cut = LeftoverCut::of(Digest::sha256Of('plan'), [1 => [[leftoverCutMutant(1), 900.0], [leftoverCutMutant(2), 800.0]]], [1 => Seconds::of(60.0)]);

    expect(leftoverCutShares($cut->ofShard(ShardId::of(1))))->toBe(['1.1: 1', '1.2: 2']);
});

it('gives a shard a further share for seconds past a whole job, and one share to mutants predicted to take none', function (): void {
    $cut = LeftoverCut::of(Digest::sha256Of('plan'), [
        1 => [[leftoverCutMutant(1), 31.0], [leftoverCutMutant(2), 30.0]],
        2 => [[leftoverCutMutant(3), 0.0], [leftoverCutMutant(4), 0.0]],
    ], [1 => Seconds::of(60.0), 2 => Seconds::of(60.0)]);

    expect(leftoverCutShares([...$cut->ofShard(ShardId::of(1)), ...$cut->ofShard(ShardId::of(2))]))
        ->toBe(['1.1: 1', '1.2: 2', '2.1: 3 4']);
});
