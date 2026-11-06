<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Subsumption\Brackets;

it('knows the brackets that open a group', function (): void {
    expect(array_map(Brackets::opens(...), ['(', '[', ')', ']', '{', '$a']))->toBe([true, true, false, false, false, false]);
});

it('knows tokens that close every group they open, and none they did not', function (string $tokens, bool $balanced): void {
    expect(Brackets::areBalanced(explode(' ', $tokens)))->toBe($balanced);
})->with([
    'no brackets' => ['$a > $b', true],
    'nested groups' => ['( $a [ 0 ] )', true],
    'a group left open' => ['( $a', false],
    'a group closed before it opens' => [') ( $a', false],
    'a group closed past what it opened' => ['$a ) ( $b', false],
]);

it('finds the bracket that closes the group one opens, and none where the tokens end first', function (): void {
    $tokens = ['f', '(', '$a', '[', '0', ']', ')', ';'];

    expect([Brackets::closing($tokens, 1), Brackets::closing($tokens, 3), Brackets::closing(['(', '$a'], 0)])
        ->toEqual([6, 5, NotGiven::value()]);
});
