<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\TokenAlignment;

/**
 * Where each index of the second stands in the first, from the first index to one past the last, the alignment
 * raising no warning.
 *
 * @param list<string> $first
 * @param list<string> $second
 *
 * @return list<int>
 */
function alignedOnto(array $first, array $second): array
{
    $raised = [];
    set_error_handler(static function (int $level, string $message) use (&$raised): bool {
        $raised[] = $message;

        return true;
    });

    try {
        $alignment = TokenAlignment::of($first, $second);
    } finally {
        restore_error_handler();
    }

    expect($raised)->toBe([]);

    return array_map($alignment->inFirst(...), range(0, count($second)));
}

it('carries each token the two share to where it stands in the first, and past the last after the end', function (): void {
    expect(alignedOnto(['a', 'b', 'c'], ['a', 'b', 'c']))->toBe([0, 1, 2, 3])
        ->and(alignedOnto([], []))->toBe([0]);
});

it('carries a token the first does not hold to the token after the last one shared before it', function (): void {
    expect(alignedOnto(['a', 'b', 'c'], ['a', 'x', 'c']))->toBe([0, 1, 2, 3])
        ->and(alignedOnto(['a', 'b'], ['x']))->toBe([0, 0])
        ->and(alignedOnto(['a', 'b'], ['x', 'b']))->toBe([0, 1, 2])
        ->and(alignedOnto(['a'], ['a', 'x', 'y']))->toBe([0, 1, 1, 1]);
});

it('skips a token only the first holds, as a print drops a trailing comma', function (): void {
    expect(alignedOnto(['f', '(', '1', ',', ')', ';'], ['f', '(', '1', ')', ';']))->toBe([0, 1, 2, 4, 5, 6])
        ->and(alignedOnto(['a', ',', ',', 'b'], ['a', 'b']))->toBe([0, 3, 4]);
});

it('carries tokens only the second holds, as a print adds parentheses, to the token after', function (): void {
    expect(alignedOnto(['new', 'A', '->', 'b'], ['(', 'new', 'A', ')', '->', 'b']))->toBe([0, 0, 1, 2, 2, 3, 4]);
});

it('keeps a longest run of tokens the two share in order, as Myers\' own example finds it', function (): void {
    expect(alignedOnto(['a', 'b', 'c', 'a', 'b', 'b', 'a'], ['c', 'b', 'a', 'b', 'a', 'c']))->toBe([2, 3, 3, 4, 6, 7, 7])
        ->and(alignedOnto(['x', 'a', 'b'], ['a', 'b', 'x']))->toBe([1, 2, 3, 3]);
});

it('stops at the step that reaches the end of both, so two long texts a token apart align as fast as two short ones', function (): void {
    $first = array_fill(0, 20000, 'same');
    $second = [...array_slice($first, 0, 10000), 'added', ...array_slice($first, 10000)];
    $alignment = TokenAlignment::of($first, $second);

    expect([$alignment->inFirst(9999), $alignment->inFirst(10000), $alignment->inFirst(10001), $alignment->inFirst(20000)])
        ->toBe([9999, 10000, 10000, 19999]);
});
