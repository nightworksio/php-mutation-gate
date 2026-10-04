<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\AnyMutator;
use NightWorksIO\MutationGate\Adapter\Infection\Bridges;
use NightWorksIO\MutationGate\Adapter\Infection\IgnorePattern;
use NightWorksIO\MutationGate\Adapter\Infection\MutatorSettings;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;

it('reads every pattern that hides mutants, with the key it is under and the mutator it applies to', function (): void {
    $settings = MutatorSettings::of(Node::decode((string) json_encode([
        '@default' => true,
        'global-ignore' => ['A::*', 3],
        'global-ignoreSourceCodeByRegex' => 'not a list',
        '@arithmetic' => ['ignore' => ['B']],
        'Plus' => ['ignoreSourceCodeByRegex' => ['x.*'], 'ignore' => ['a' => 'C']],
        'Minus' => false,
    ])));

    expect($settings->patterns())->toEqual([
        IgnorePattern::overNames('global-ignore', AnyMutator::of(), 'A::*'),
        IgnorePattern::overNames('global-ignore', AnyMutator::of(), ''),
        IgnorePattern::overNames('@arithmetic.ignore', AnyMutator::of(), 'B'),
        IgnorePattern::overSource('Plus.ignoreSourceCodeByRegex', 'Plus', 'x.*'),
    ]);
});

it('keeps the whole block for a run of every mutator, and nothing where the project has none', function (): void {
    expect(MutatorSettings::of(Node::decode('{"@default": true, "Plus": {}, "global-ignore": []}'))->narrowedTo(Mutators::all()))
        ->toBe(['mutators' => '{"@default":true,"Plus":{},"global-ignore":[]}'])
        ->and(MutatorSettings::of(Node::decode('{}'))->narrowedTo(Mutators::all()))->toBe([])
        ->and(MutatorSettings::of(Node::decode('true'))->narrowedTo(Mutators::all()))->toBe([]);
});

it('turns a named mutator on bare where the project gives it no settings, keeping both global ignores', function (): void {
    expect(MutatorSettings::of(Node::decode('{"Plus": {}, "Minus": false, "global-ignore": [], "global-ignoreSourceCodeByRegex": []}'))->narrowedTo(Mutators::named('Plus', 'Minus')))
        ->toBe(['mutators' => '{"global-ignore":[],"global-ignoreSourceCodeByRegex":[],"Plus":true,"Minus":true}']);
});

it('reads a mutator whose name reads as a number as any other', function (): void {
    $settings = MutatorSettings::of(Node::decode('{"12": {"ignore": ["A"]}, "Plus": true}'));

    expect($settings->patterns())->toEqual([IgnorePattern::overNames('12.ignore', '12', 'A')])
        ->and($settings->narrowedTo(Mutators::all()))->toBe(['mutators' => '{"12":{"ignore":["A"]},"Plus":true}'])
        ->and($settings->narrowedTo(Mutators::named('12')))->toBe(['mutators' => '{"12":{"ignore":["A"]}}']);
});

it('turns every bridge on beside the project\'s block, and beside Infection\'s default profile where it has none', function (): void {
    $bridges = Bridges::to(Enabled::of(MutatorSet::of(PlusToMinus::class)));
    $bridge = 'NightWorksIO\\\\MutationGateBridge\\\\Infection\\\\NightWorksIO\\\\MutationGate\\\\Tests\\\\Support\\\\Mutators\\\\PlusToMinus';

    expect(MutatorSettings::of(Node::decode('{"@arithmetic": true}'))->narrowedTo(Mutators::all(), $bridges))
        ->toBe(['mutators' => sprintf('{"@arithmetic":true,"%s":true}', $bridge)])
        ->and(MutatorSettings::of(Node::decode('{}'))->narrowedTo(Mutators::all(), $bridges))
        ->toBe(['mutators' => sprintf('{"@default":true,"%s":true}', $bridge)]);
});

it('names a bridged mutator by its bridge for a run that names it', function (): void {
    $bridges = Bridges::to(Enabled::of(MutatorSet::of(PlusToMinus::class)));

    expect(MutatorSettings::of(Node::decode('{"global-ignore": ["A"]}'))->narrowedTo(Mutators::named('acme/PlusToMinus', 'Minus'), $bridges))
        ->toBe(['mutators' => '{"global-ignore":["A"],"NightWorksIO\\\\MutationGateBridge\\\\Infection\\\\NightWorksIO\\\\MutationGate\\\\Tests\\\\Support\\\\Mutators\\\\PlusToMinus":true,"Minus":true}']);
});
