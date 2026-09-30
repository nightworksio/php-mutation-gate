<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cluster\ClusterId;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;

$id = static fn(string $diff): MutantId => MutantId::hash(Path::of('src/Cart.php'), 'M', $diff, 0);

it('is c and eleven hex characters of a SHA-256 over the sorted member ids, one to a line', function () use ($id): void {
    $members = [$id('-a'), $id('-b'), $id('-c')];
    $sorted = array_map(static fn(MutantId $member): string => $member->value(), $members);
    sort($sorted);

    $expected = sprintf('c%s', mb_substr(hash('sha256', implode("\n", $sorted)), 0, 11));

    expect(ClusterId::of(MutantIds::of(...$members))->value())->toBe($expected)
        ->and(ClusterId::of(MutantIds::of(...array_reverse($members)))->value())->toBe($expected)
        ->and($expected)->toMatch('/^c[0-9a-f]{11}$/');
});

it('names the same members alike in any order, and other members otherwise', function () use ($id): void {
    $one = $id('-a');
    $other = $id('-b');

    expect(ClusterId::of(MutantIds::of($other, $one)))->toEqual(ClusterId::of(MutantIds::of($one, $other)))
        ->and(ClusterId::of(MutantIds::of($one)))->not->toEqual(ClusterId::of(MutantIds::of($one, $other)));
});

it('reads an id as a report writes it', function (): void {
    $read = ClusterId::parse('c0123456789a');

    expect($read)->toBeInstanceOf(ClusterId::class)
        ->and($read instanceof ClusterId ? $read->value() : '')->toBe('c0123456789a');
});

it('refuses what is not a cluster id, a mutant id included', function (string $id): void {
    $read = ClusterId::parse($id);

    expect($read)->toBeInstanceOf(CannotJudge::class)
        ->and($read instanceof CannotJudge ? $read->why() : '')
        ->toBe(sprintf('"%s" is not a cluster id, which is "c" and eleven lowercase hex characters, as every report prints it.', $id));
})->with([
    'a mutant id' => ['0123456789ab'],
    'too short' => ['c0123456789'],
    'too long' => ['c0123456789ab'],
    'upper case' => ['c0123456789A'],
    'another letter first' => ['d0123456789a'],
    'a line break after it' => ["c0123456789a\n"],
]);
