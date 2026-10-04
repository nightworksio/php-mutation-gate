<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\NamedMutators;

it('holds mutators by the names the gate gives their mutants, in order', function (): void {
    expect([...NamedMutators::of('acme/PlusToMinus', 'Plus')])->toBe(['acme/PlusToMinus', 'Plus'])
        ->and([...NamedMutators::of()])->toBe([]);
});

it('meets others where any name is among theirs', function (): void {
    $named = NamedMutators::of('acme/PlusToMinus', 'Plus');

    expect($named->meet(NamedMutators::of('Minus', 'Plus')))->toBeTrue()
        ->and($named->meet(NamedMutators::of('Minus')))->toBeFalse()
        ->and($named->meet(NamedMutators::of()))->toBeFalse();
});
