<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\Kills;
use NightWorksIO\MutationGate\Core\Order\RankedMutant;
use NightWorksIO\MutationGate\Core\Order\Ranking;
use NightWorksIO\MutationGate\Core\Proof\PlacedRankings;
use NightWorksIO\MutationGate\Core\Test\TestId;

$makeId = static fn(string $diff): MutantId => MutantId::hash(Path::of('src/Money.php'), 'Plus', $diff, 0);

it('places a history\'s rankings where the proofs record their mutants, the newest last, and keeps the others by id', function () use ($makeId): void {
    $placed = $makeId("-a\n+b");
    $kept = $makeId("-c\n+d");
    $history = KillHistory::none()
        ->withMutant($kept, Ranking::of(Kills::of(TestId::of('t'), 1)))
        ->withMutant($placed, Ranking::of(Kills::of(TestId::of('u'), 2)));
    $places = [$placed->value() => [3, 0]];

    expect(PlacedRankings::of($history, ['t' => 0, 'u' => 1], $places))->toBe([[3, 0, [[1, 2]]]])
        ->and(PlacedRankings::unplaced($history, ['t' => 0, 'u' => 1], $places))->toBe([$kept->value() => [[0, 1]]]);
});

it('keeps every ranking no proof places, wherever a placed one comes among them', function () use ($makeId): void {
    $placed = $makeId("-a\n+b");
    $kept = $makeId("-c\n+d");
    $history = KillHistory::none()
        ->withMutant($placed, Ranking::of(Kills::of(TestId::of('u'), 2)))
        ->withMutant($kept, Ranking::of(Kills::of(TestId::of('t'), 1)));

    expect(PlacedRankings::unplaced($history, ['t' => 0, 'u' => 1], [$placed->value() => [3, 0]]))
        ->toBe([$kept->value() => [[0, 1]]]);
});

it('names the proofs placed rankings point into, skipping one that names none', function (): void {
    expect(PlacedRankings::proofsIn(Node::decode('[[2, 0, [[0, 1]]], [5, 1, []], [], ["x", 0, []], 7]')))->toBe([2 => true, 5 => true])
        ->and(PlacedRankings::proofsIn(Node::decode('{"a": 1}')))->toBe([]);
});

it('reads each placed ranking whose proof and place name a mutant and whose ranking keeps a pair, and drops any other', function () use ($makeId): void {
    $id = $makeId("-a\n+b");
    $proofs = [0 => ['not an id', $id->value()], 2 => [$id->value()]];
    $placed = Node::decode(sprintf('[%s]', implode(',', [
        '[0, 1, [[0, 2]]]',
        '[0, 0, [[0, 1]]]',
        '[2, 0, [[9, 1]]]',
        '[1, 0, [[0, 1]]]',
        '[0, 5, [[0, 1]]]',
        '[0, 1]',
        '["0", 1, [[0, 1]]]',
        '[0, "1", [[0, 1]]]',
    ])));

    expect(PlacedRankings::read($placed, ['t'], $proofs))->toEqual([RankedMutant::of($id, Ranking::of(Kills::of(TestId::of('t'), 2)))]);
});
