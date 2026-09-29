<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\Mutators;

it('applies every mutator the runner has, naming none', function (): void {
    $all = Mutators::all();

    expect($all->isAll())->toBeTrue()
        ->and($all)->toHaveCount(0)
        ->and(iterator_to_array($all, preserve_keys: true))->toBe([]);
});

it('applies only the mutators it names, each once, in the order named', function (): void {
    $named = Mutators::named('A\\LessThan', 'A\\LessThan', 'B\\Plus', 'A\\LessThan');

    expect($named->isAll())->toBeFalse()
        ->and($named)->toHaveCount(2)
        ->and(iterator_to_array($named, preserve_keys: true))->toBe(['A\\LessThan', 'B\\Plus']);
});

it('keeps the first name it is given', function (): void {
    expect(iterator_to_array(Mutators::named('A\\LessThan', 'B\\Plus'), preserve_keys: true))->toBe(['A\\LessThan', 'B\\Plus']);
});
