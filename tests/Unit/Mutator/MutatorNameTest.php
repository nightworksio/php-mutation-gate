<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NotAMutatorName;

it('is a set and a name within it, written with a slash between', function (): void {
    $name = MutatorName::of('laravel-auth', 'GateAllowsToTrue');

    expect($name->set())->toBe('laravel-auth')
        ->and($name->own())->toBe('GateAllowsToTrue')
        ->and($name->value())->toBe('laravel-auth/GateAllowsToTrue');
});

it('refuses a set or a name not written as one', function (string $set, string $own): void {
    MutatorName::of($set, $own);
})->with([
    'a set in upper case' => ['Laravel', 'GateAllowsToTrue'],
    'a set with a doubled hyphen' => ['laravel--auth', 'GateAllowsToTrue'],
    'a set ending in a hyphen' => ['laravel-', 'GateAllowsToTrue'],
    'a set with a slash' => ['acme/laravel', 'GateAllowsToTrue'],
    'an empty set' => ['', 'GateAllowsToTrue'],
    'a name in lower case' => ['laravel', 'gateAllowsToTrue'],
    'a name with an underscore' => ['laravel', 'Gate_Allows'],
    'a name over two lines' => ['laravel', "Gate\n"],
])->throws(NotAMutatorName::class);

it('says what a set and a name are written as', function (): void {
    MutatorName::of('Laravel', 'gate');
})->throws(NotAMutatorName::class, '"Laravel/gate" is not a mutator name: a set is lower case letters, digits and hyphens, and a mutator a letter in upper case, then letters and digits.');
