<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\MutatorSettings;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;

it('reads every pattern that hides mutants, with the key it is under and the mutator it applies to', function (): void {
    $settings = MutatorSettings::of(Node::decode((string) json_encode([
        '@default' => true,
        'global-ignore' => ['A::*', 3],
        'global-ignoreSourceCodeByRegex' => 'not a list',
        '@arithmetic' => ['ignore' => ['B']],
        'Plus' => ['ignoreSourceCodeByRegex' => ['x.*'], 'ignore' => ['a' => 'C']],
        'Minus' => false,
    ])));

    expect($settings->patterns())->toBe([
        ['key' => 'global-ignore', 'regex' => false, 'mutator' => '', 'pattern' => 'A::*'],
        ['key' => 'global-ignore', 'regex' => false, 'mutator' => '', 'pattern' => ''],
        ['key' => '@arithmetic.ignore', 'regex' => false, 'mutator' => '', 'pattern' => 'B'],
        ['key' => 'Plus.ignoreSourceCodeByRegex', 'regex' => true, 'mutator' => 'Plus', 'pattern' => 'x.*'],
    ]);
});

it('keeps the whole block for a run of every mutator, and nothing where the project has none', function (): void {
    expect(MutatorSettings::of(Node::decode('{"@default": true, "Plus": {}, "global-ignore": []}'))->narrowedTo(Mutators::all()))
        ->toBe(['mutators' => '{"@default":true,"Plus":{},"global-ignore":[]}'])
        ->and(MutatorSettings::of(Node::decode('{}'))->narrowedTo(Mutators::all()))->toBe([])
        ->and(MutatorSettings::of(Node::decode('true'))->narrowedTo(Mutators::all()))->toBe([]);
});

it('turns a named mutator on bare where the project gives it no settings', function (): void {
    expect(MutatorSettings::of(Node::decode('{"Plus": {}, "Minus": false, "global-ignore": []}'))->narrowedTo(Mutators::named('Plus', 'Minus')))
        ->toBe(['mutators' => '{"global-ignore":[],"Plus":true,"Minus":true}']);
});
