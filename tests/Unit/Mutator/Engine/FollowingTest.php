<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Mutator\Engine\Following;

it('reads the lines after a newline one by one, and none past the last', function (): void {
    $first = Following::lines("a\nb\n\nc", 1);
    $last = $first->next()->next();

    expect($first->line())->toBe('b')
        ->and($first->next()->line())->toBe('')
        ->and($last->line())->toBe('c')
        ->and($last->read())->toBe('c')
        ->and($last->next()->line())->toBeInstanceOf(NotGiven::class)
        ->and($last->next()->read())->toBe('');
});

it('has no line after the end of a code, and an empty one after a newline that ends it', function (): void {
    expect(Following::lines('a', 1)->line())->toBeInstanceOf(NotGiven::class)
        ->and(Following::lines("a\n", 1)->line())->toBe('')
        ->and(Following::lines("a\n", 1)->next()->line())->toBeInstanceOf(NotGiven::class);
});
