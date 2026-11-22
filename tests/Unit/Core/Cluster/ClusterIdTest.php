<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cluster\ClusterId;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\IdPrefix;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;

/** A member's id as a report spells it, which may read as a number. */
function clusterIdMember(string $id): MutantId
{
    $parsed = MutantId::parse($id);

    return $parsed instanceof MutantId ? $parsed : throw new RuntimeException($parsed->why());
}

$id = static fn(string $diff): MutantId => MutantId::hash(Path::of('src/Cart.php'), 'M', $diff, 0);

it('is k and eleven hex characters of a SHA-256 over the sorted member ids, one to a line', function () use ($id): void {
    $members = [$id('-a'), $id('-b'), $id('-c')];
    $sorted = array_map(static fn(MutantId $member): string => $member->value(), $members);
    sort($sorted, SORT_STRING);

    $expected = sprintf('k%s', mb_substr(hash('sha256', implode("\n", $sorted)), 0, 11));

    expect(ClusterId::of(MutantIds::of(...$members))->value())->toBe($expected)
        ->and(ClusterId::of(MutantIds::of(...array_reverse($members)))->value())->toBe($expected)
        ->and($expected)->toMatch('/^k[0-9a-f]{11}$/');
});

it('names the same members alike in any order, and other members otherwise', function () use ($id): void {
    $one = $id('-a');
    $other = $id('-b');

    expect(ClusterId::of(MutantIds::of($other, $one)))->toEqual(ClusterId::of(MutantIds::of($one, $other)))
        ->and(ClusterId::of(MutantIds::of($one)))->not->toEqual(ClusterId::of(MutantIds::of($one, $other)));
});

it('reads an id as a report writes it', function (): void {
    $read = ClusterId::parse('k0123456789a');

    expect($read)->toBeInstanceOf(ClusterId::class)
        ->and($read instanceof ClusterId ? $read->value() : '')->toBe('k0123456789a');
});

it('refuses what is not a cluster id, a mutant id included', function (string $id): void {
    $read = ClusterId::parse($id);

    expect($read)->toBeInstanceOf(CannotJudge::class)
        ->and($read instanceof CannotJudge ? $read->why() : '')
        ->toBe(sprintf('"%s" is not a cluster id, which is "k" and eleven lowercase hex characters, as every report prints it.', $id));
})->with([
    'a mutant id' => ['0123456789ab'],
    'too short' => ['k0123456789'],
    'too long' => ['k0123456789ab'],
    'upper case' => ['k0123456789A'],
    'another letter first' => ['c0123456789a'],
    'a line break after it' => ["k0123456789a\n"],
]);

it('reads an id a command line writes as a cluster\'s where it begins with k, and as a mutant\'s otherwise', function (): void {
    $cluster = ClusterId::orMutant('k0123456789a');
    $mutant = ClusterId::orMutant('c0ffee');
    $neither = ClusterId::orMutant('k0123');

    expect($cluster)->toBeInstanceOf(ClusterId::class)
        ->and($mutant)->toBeInstanceOf(IdPrefix::class)
        ->and($neither instanceof CannotJudge ? $neither->why() : '')
        ->toBe('"k0123" is not a cluster id, which is "k" and eleven lowercase hex characters, as every report prints it.');
});

it('names the same members alike in whatever order they arrive, their ids in byte order however they read', function (): void {
    $ids = ['105436102654', '06270e550940', '0a1b2c3d4e5f', '097b2ebee2d4'];
    $members = array_map(clusterIdMember(...), $ids);
    $named = [];

    foreach ([[0, 1, 2, 3], [3, 2, 1, 0], [1, 3, 0, 2], [2, 0, 3, 1], [0, 2, 1, 3], [3, 1, 2, 0], [1, 0, 3, 2], [2, 3, 0, 1]] as $order) {
        $named[] = ClusterId::of(MutantIds::of(...array_map(static fn(int $at): MutantId => $members[$at], $order)))->value();
    }

    expect(array_values(array_unique($named)))
        ->toBe([sprintf('k%s', mb_substr(hash('sha256', "06270e550940\n097b2ebee2d4\n0a1b2c3d4e5f\n105436102654"), 0, 11))]);
});
