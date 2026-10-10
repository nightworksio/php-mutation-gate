<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\Name;

it('names each built-in runner by the name a config chooses it by', function (): void {
    foreach (BuiltinRunner::cases() as $builtin) {
        expect($builtin->named())->toEqual(Name::of($builtin->value));
    }
});

it('says which built-in runners make their mutants with their own mutators', function (): void {
    expect(array_map(static fn(BuiltinRunner $builtin): bool => $builtin->makesItsOwnMutants(), BuiltinRunner::cases()))
        ->toBe([true, true, false]);
});
