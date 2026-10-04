<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\EveryKiller;

it('drops every --bail from a mutant\'s own process under a full kill matrix', function (): void {
    expect(EveryKiller::of(['vendor/bin/pest', '--bail', '--parallel', '--bail'], 'full', '/m/Money.php'))
        ->toBe(['vendor/bin/pest', '--parallel']);
});

it('keeps the arguments of a run that records first killers, or of a process that is no mutant\'s', function (
    string|false $matrix,
    string|false $mutated,
): void {
    expect(EveryKiller::of(['vendor/bin/pest', '--bail'], $matrix, $mutated))->toBe(['vendor/bin/pest', '--bail']);
})->with([
    'first killers' => ['first-killer', '/m/Money.php'],
    'no matrix asked' => [false, '/m/Money.php'],
    'a matrix spelt otherwise' => ['FULL', '/m/Money.php'],
    'no mutant' => ['full', false],
    'an empty mutant' => ['full', ''],
]);
