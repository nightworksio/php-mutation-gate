<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Order\Kills;
use NightWorksIO\MutationGate\Core\Order\Ranking;
use NightWorksIO\MutationGate\Core\Test\TestId;

/**
 * Each test a ranking holds, with its kills, in its order.
 *
 * @return list<string>
 */
function rankingRead(Ranking $ranking): array
{
    return array_map(
        static fn(Kills $kills): string => sprintf('%s %d', $kills->test()->value(), $kills->count()),
        [...$ranking],
    );
}

it('ranks tests by their kills, most first, keeping the order it was given between equals and the five most', function (): void {
    $ranking = Ranking::of(
        Kills::of(TestId::of('a'), 1),
        Kills::of(TestId::of('b'), 4),
        Kills::of(TestId::of('c'), 1),
        Kills::of(TestId::of('d'), 2),
        Kills::of(TestId::of('e'), 1),
        Kills::of(TestId::of('f'), 1),
    );

    expect(rankingRead($ranking))->toBe(['b 4', 'd 2', 'a 1', 'c 1', 'e 1'])
        ->and(count($ranking))->toBe(5)
        ->and(count(Ranking::none()))->toBe(0);
});

it('counts one more kill, putting the test ahead of every other with as many, so a new killer displaces an old one', function (): void {
    $full = Ranking::of(
        Kills::of(TestId::of('a'), 3),
        Kills::of(TestId::of('b'), 1),
        Kills::of(TestId::of('c'), 1),
        Kills::of(TestId::of('d'), 1),
        Kills::of(TestId::of('e'), 1),
    );

    expect(rankingRead($full->killedBy(TestId::of('new'))))->toBe(['a 3', 'new 1', 'b 1', 'c 1', 'd 1'])
        ->and(rankingRead($full->killedBy(TestId::of('d'))))->toBe(['a 3', 'd 2', 'b 1', 'c 1', 'e 1'])
        ->and(rankingRead($full->killedBy(TestId::of('a'))))->toBe(['a 4', 'b 1', 'c 1', 'd 1', 'e 1'])
        ->and(rankingRead(Ranking::none()->killedBy(TestId::of('a'))))->toBe(['a 1']);
});

it('names its tests, most kills first', function (): void {
    $tests = Ranking::of(Kills::of(TestId::of('a'), 1), Kills::of(TestId::of('b'), 2))->tests();

    expect(array_map(static fn(TestId $test): string => $test->value(), [...$tests]))->toBe(['b', 'a']);
});
