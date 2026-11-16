<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\Cell;

it('reads the cell at a row and a column, and nothing past either', function (): void {
    $rows = [['a', 'b'], ['c']];

    expect(Cell::of($rows, 0, 1))->toBe('b')
        ->and(Cell::of($rows, 1, 0))->toBe('c')
        ->and(Cell::of($rows, 1, 1))->toBe('')
        ->and(Cell::of($rows, 2, 0))->toBe('')
        ->and(Cell::in(['a'], 0))->toBe('a')
        ->and(Cell::in(['a'], 1))->toBe('');
});
