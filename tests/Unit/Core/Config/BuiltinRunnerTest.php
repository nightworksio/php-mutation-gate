<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Runner\Program;

it('names each built-in runner by the name a config chooses it by', function (): void {
    foreach (BuiltinRunner::cases() as $builtin) {
        expect($builtin->named())->toEqual(Name::of($builtin->value));
    }
});

it('says which built-in runners make their mutants with their own mutators', function (): void {
    expect(array_map(static fn(BuiltinRunner $builtin): bool => $builtin->makesItsOwnMutants(), BuiltinRunner::cases()))
        ->toBe([true, true, false, false, false, false]);
});

it('names the package whose being installed chooses each built-in runner', function (): void {
    expect(array_map(static fn(BuiltinRunner $builtin): string => $builtin->package()->value, BuiltinRunner::cases()))
        ->toBe(['pestphp/pest-plugin-mutate', 'infection/infection', 'phpunit/phpunit', 'phpspec/phpspec', 'codeception/codeception', 'testo/testo']);
});

it('names the program each built-in runner runs', function (): void {
    expect(array_map(static fn(BuiltinRunner $builtin): Program => $builtin->program(), BuiltinRunner::cases()))
        ->toBe([Program::Pest, Program::Infection, Program::PhpUnit, Program::PhpSpec, Program::Codeception, Program::Testo]);
});
