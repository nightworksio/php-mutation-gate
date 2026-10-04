<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\MutatorSetName;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('reads the sets turned on and the mutators turned off, each once', function (): void {
    $settings = Configs::settings([
        'runner' => 'pest',
        'mutators' => ['sets' => ['laravel', 'acme-auth', 'laravel'], 'except' => ['laravel/RemoveAbort']],
    ]);

    expect(array_map(static fn(object $name): string => $name->value(), [...$settings->mutators()->sets()]))
        ->toBe(['laravel', 'acme-auth'])
        ->and([...$settings->mutators()->except()])->toBe(['laravel/RemoveAbort']);
});

it('refuses a set or a mutator not written as its name is', function (array $mutators, string $problem): void {
    expect(Configs::problems(Configs::validated(['runner' => 'pest', 'mutators' => $mutators])))->toBe([$problem]);
})->with([
    'a set in upper case' => [
        ['sets' => ['Laravel']],
        "mutators.sets[0]: expected a mutator set's name: lower case letters and digits, joined by single hyphens, got \"Laravel\"",
    ],
    'a mutator without its set' => [
        ['except' => ['RemoveAbort']],
        "mutators.except[0]: expected a mutator's name, <set>/<Name>, got \"RemoveAbort\"",
    ],
    'a mutator in lower case' => [
        ['except' => ['laravel/removeAbort']],
        "mutators.except[0]: expected a mutator's name, <set>/<Name>, got \"laravel/removeAbort\"",
    ],
]);

it('refuses the set this repository ships, which is always on', function (): void {
    expect(Configs::problems(Configs::validated(['runner' => 'pest', 'mutators' => ['sets' => ['laravel', 'default']]])))->toBe([
        'mutators.sets[1]: expected a set other than "default", which is always on: Pest and Infection run their own mutators in its place',
    ]);
});

it('describes a set\'s name to the schema, never the set this repository ships', function (): void {
    expect(MutatorSetName::turnedOn()->schema()->line())->toBe(
        '{"type":"string","minLength":1,"pattern":"^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$","not":{"const":"default"}}',
    )->and(MutatorSetName::turnedOn()->expected())->toBe("a mutator set's name: lower case letters and digits, joined by single hyphens")
        ->and(MutatorSetName::turnedOn()->effects())->toBe([]);
});
