<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Plan\LeftForks;
use NightWorksIO\MutationGate\Core\Plan\LeftMutant;
use NightWorksIO\MutationGate\Core\Plan\LeftoverCut;
use NightWorksIO\MutationGate\Core\Plan\LeftoverShare;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\StreamWall;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/** The id of the mutant on this line of src/Money.php. */
function leftoverCutId(int $line): MutantId
{
    return MutantId::hash(Path::of('src/Money.php'), 'Minus', '- a + b', $line);
}

/**
 * The mutant on this line of a source file, as a share lists it, with the test files covering it and its forks.
 *
 * @param  list<string>                                     $covering
 * @return array{LeftMutant, list<string>, LeftForks}
 */
function leftoverCutMutant(int $line, string $file = 'src/Money.php', array $covering = []): array
{
    return leftoverCutPriced($line, $file, $covering, LeftForks::atKiller(0.0));
}

/**
 * The same, its forks priced so.
 *
 * @param  list<string>                                $covering
 * @return array{LeftMutant, list<string>, LeftForks}
 */
function leftoverCutPriced(int $line, string $file, array $covering, LeftForks $forks): array
{
    return [LeftMutant::of(leftoverCutId($line), sprintf('Minus-%d', $line), Path::of($file)), $covering, $forks];
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

it('cuts each shard\'s left mutants by source file, priced by the test files covering them, each to the share it adds least to that it fits', function (): void {
    $files = ['T1' => 30.0, 'T2' => 20.0, 'T3' => 40.0, 'T4' => 10.0, 'T5' => 5.0];
    $cut = LeftoverCut::of(Digest::sha256Of('plan'), [
        3 => [
            leftoverCutMutant(1, 'src/A.php', ['T1']),
            leftoverCutMutant(2, 'src/B.php', ['T1']),
            leftoverCutMutant(3, 'src/A.php', ['T2']),
            leftoverCutMutant(4, 'src/C.php', ['T3']),
            leftoverCutMutant(5, 'src/D.php', ['T4']),
            leftoverCutMutant(10, 'src/G.php', ['T4']),
            leftoverCutMutant(9, 'src/F.php', ['T5']),
        ],
        7 => [leftoverCutMutant(6, 'src/H.php', ['T3', 'T4']), leftoverCutMutant(7, 'src/I.php', ['T1', 'T2']), leftoverCutMutant(8, 'src/J.php', ['T2'])],
        9 => [],
    ], $files, [3 => Seconds::of(60.0), 7 => Seconds::of(70.0), 9 => Seconds::of(60.0)], StreamWall::of(1));

    expect(leftoverCutShares($cut->ofShard(ShardId::of(3))))->toBe(['3.1: 1 3 2 5 10', '3.2: 4 9'])
        ->and(leftoverCutShares($cut->ofShard(ShardId::of(7))))->toBe(['7.1: 6', '7.2: 7 8'])
        ->and($cut->ofShard(ShardId::of(9)))->toBe([])
        ->and($cut->cutFrom(Digest::sha256Of('plan')))->toBeTrue();
});

it('splits a source file\'s mutants that do not fit a job together into mutants of their own, each that fits no job alone in a share of its own', function (): void {
    $cut = LeftoverCut::of(Digest::sha256Of('plan'), [1 => [
        leftoverCutMutant(1, 'src/A.php', ['Slow']),
        leftoverCutMutant(2, 'src/B.php', ['Slower']),
        leftoverCutMutant(3, 'src/B.php', ['Slow']),
        leftoverCutMutant(4, 'src/C.php', ['Quick']),
        leftoverCutMutant(5, 'src/C.php', ['Slow']),
    ]], ['Slow' => 900.0, 'Slower' => 800.0, 'Quick' => 10.0], [1 => Seconds::of(60.0)], StreamWall::of(1));

    expect(leftoverCutShares($cut->ofShard(ShardId::of(1))))->toBe(['1.1: 1', '1.2: 3', '1.3: 5', '1.4: 2', '1.5: 4']);
});

it('prices a share at the wall seconds its processes take over its test files and its mutants\' forks, a second pass\'s scaled, no less than its longest file, and records both passes', function (): void {
    $left = [1 => [
        leftoverCutPriced(1, 'src/A.php', ['T1'], LeftForks::atKiller(8.0)),
        leftoverCutPriced(2, 'src/A.php', ['T2'], LeftForks::atEveryTest(40.0)),
        leftoverCutPriced(3, 'src/B.php', ['Long'], LeftForks::atKiller(0.0)),
    ]];
    $files = ['T1' => 20.0, 'T2' => 12.0, 'Long' => 30.0];
    $one = LeftoverCut::of(Digest::sha256Of('plan'), $left, $files, [1 => Seconds::of(60.0)], StreamWall::of(4, 0.5))->ofShard(ShardId::of(1));
    $two = LeftoverCut::of(Digest::sha256Of('plan'), $left, $files, [1 => Seconds::of(60.0)], StreamWall::of(1))->ofShard(ShardId::of(1));
    $passes = static fn(LeftoverShare $share): array => [$share->predicted()->first()->seconds(), $share->predicted()->second()->seconds()];

    expect(leftoverCutShares($one))->toBe(['1.1: 3 1 2'])
        ->and(array_map($passes, $one))->toBe([[30.0, 5.0]])
        ->and(leftoverCutShares($two))->toBe(['1.1: 2', '1.2: 3 1'])
        ->and(array_map($passes, $two))->toBe([[12.0, 40.0], [58.0, 0.0]]);
});

it('puts mutants no test file is timed for in one share, and gives a shard with no capacity left a second for each', function (): void {
    $cut = LeftoverCut::of(Digest::sha256Of('plan'), [
        1 => [leftoverCutMutant(1, 'src/A.php', ['Untimed']), leftoverCutMutant(2, 'src/B.php')],
        2 => [leftoverCutMutant(3, 'src/A.php', ['T1']), leftoverCutMutant(4, 'src/B.php', ['T2'])],
    ], ['T1' => 1.0, 'T2' => 1.0], [1 => Seconds::of(60.0)], StreamWall::of(1));

    expect(leftoverCutShares([...$cut->ofShard(ShardId::of(1)), ...$cut->ofShard(ShardId::of(2))]))
        ->toBe(['1.1: 1 2', '2.1: 3', '2.2: 4']);
});

it('never cuts more shares than one matrix of jobs holds, growing every capacity until they fit', function (): void {
    $left = [];
    $files = [];

    foreach (range(1, 19) as $shard) {
        foreach (range(1, 3200) as $mutant) {
            $file = sprintf('Test%d_%d', $shard, $mutant);
            $files[$file] = 100.0;
            $left[$shard][] = [
                LeftMutant::of(MutantId::hash(Path::of('src/Money.php'), 'Minus', $file, $mutant), $file, Path::of(sprintf('src/S%d_%d.php', $shard, intdiv($mutant, 100)))),
                [$file],
                LeftForks::atKiller(0.0),
            ];
        }
    }

    $cut = LeftoverCut::of(Digest::sha256Of('plan'), $left, $files, array_fill(1, 19, Seconds::of(60.0)), StreamWall::of(1));
    $shares = array_merge(...array_map(static fn(int $shard): array => $cut->ofShard(ShardId::of($shard)), range(1, 19)));

    expect(count($shares))->toBeLessThanOrEqual(LeftoverCut::MATRIX_MOST)->toBeGreaterThan(19)
        ->and(array_sum(array_map(static fn(LeftoverShare $share): int => count($share->mutants()), $shares)))->toBe(19 * 3200);
});

it('stops growing at a share for each shard, where the shards alone are more than one matrix holds, from no capacity at all', function (): void {
    $left = [];

    foreach (range(1, LeftoverCut::MATRIX_MOST + 4) as $shard) {
        $left[$shard] = [leftoverCutMutant(1, 'src/A.php', ['T1']), leftoverCutMutant(2, 'src/B.php', ['T2'])];
    }

    $cut = LeftoverCut::of(Digest::sha256Of('plan'), $left, ['T1' => 50.0, 'T2' => 50.0], [], StreamWall::of(1));

    expect(count($cut->matrix()))->toBe(LeftoverCut::MATRIX_MOST + 4)
        ->and(leftoverCutShares($cut->ofShard(ShardId::of(1))))->toBe(['1.1: 1 2']);
});

it('closes the oldest share whole once more than the latest few are open, and still joins a later group to the open share holding its test file', function (): void {
    $files = [];
    $left = [];
    $lines = [];

    foreach (range(1, 70) as $line) {
        $files[sprintf('T%d', $line)] = 50.0 - $line / 10;
        $left[] = leftoverCutMutant($line, sprintf('src/S%d.php', $line), [sprintf('T%d', $line)]);
    }

    $left[] = leftoverCutMutant(71, 'src/Late.php', ['T70']);

    foreach (range(1, 71) as $line) {
        $lines[leftoverCutId($line)->value()] = $line;
    }

    $shares = LeftoverCut::of(Digest::sha256Of('plan'), [1 => $left], $files, [1 => Seconds::of(60.0)], StreamWall::of(1))
        ->ofShard(ShardId::of(1));
    $listed = array_map(static fn(LeftoverShare $share): array => [
        $share->number(),
        $share->predicted()->first()->seconds(),
        array_map(static fn(MutantId $id): int => $lines[$id->value()], [...$share->mutants()]),
    ], $shares);

    expect($listed)->toBe([
        ...array_map(static fn(int $line): array => [$line, 50.0 - $line / 10, [$line]], range(1, 69)),
        [70, 43.0, [70, 71]],
    ]);
});
