<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Project\TestSuite;
use NightWorksIO\MutationGate\Cli\Flow\ToldRunner;
use NightWorksIO\MutationGate\Cli\Registry\EnabledMutators;
use NightWorksIO\MutationGate\Cli\Registry\Lookup;
use NightWorksIO\MutationGate\Core\Analysis\NoAnalyser;
use NightWorksIO\MutationGate\Core\Config\BoundsOptions;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Mutators;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Core\Test\SuiteDirectory;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGate\Tests\Fakes\StaticCheckerFake;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Mutators\DecrementToIncrement;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;

/** The mutators of a registry whose `default` set holds one and whose `acme` set, which the config turns on, another. */
function toldMutators(): EnabledMutators
{
    $enabled = EnabledMutators::in(
        Lookup::in(Extensions::of(Origin::of('acme/gate'))
            ->withMutators(MutatorSet::defaultName(), MutatorSet::of(DecrementToIncrement::class))
            ->withMutators(Name::of('acme'), MutatorSet::of(PlusToMinus::class))),
        Mutators::of(Listed::of('acme')),
    );

    return $enabled instanceof EnabledMutators ? $enabled : throw new LogicException('The registry enables no mutator.');
}

/**
 * The options the gate tells the runner a config names, its checker given or not, as JSON reads them.
 *
 * @return array<mixed>
 */
function toldOptions(string $runner, bool $checked = false): array
{
    $told = ToldRunner::of(
        Configs::settings(['runner' => $runner]),
        $checked ? StaticCheckerFake::findingNothing() : NoAnalyser::configured(),
        toldMutators(),
        TestSuite::conventional(SuiteDirectory::conventional()),
    );
    $decoded = json_decode($told->options()->written()->line(), associative: true);

    return is_array($decoded) ? $decoded : [];
}

it('tells Infection the tests, the bounds, the mutators beside its own and the gate as its static analysis where one checks', function (): void {
    $checked = toldOptions('infection', checked: true);

    expect($checked['tests'] ?? null)->toBe(['tests'])
        ->and($checked['mutators'] ?? null)->toBe([PlusToMinus::class])
        ->and($checked)->toHaveKey(BoundsOptions::TIMEOUT)
        ->and($checked['staticAnalysis'] ?? null)->toBe('gate')
        ->and(toldOptions('infection'))->not->toHaveKey('staticAnalysis');
});

it('tells the gate\'s own engine the tests, the bounds and every mutator it makes mutants with, the default set\'s too', function (string $runner): void {
    $told = toldOptions($runner, checked: true);

    expect($told['tests'] ?? null)->toBe(['tests'])
        ->and($told['mutators'] ?? null)->toBe([DecrementToIncrement::class, PlusToMinus::class])
        ->and($told)->toHaveKey(BoundsOptions::TIMEOUT)
        ->and($told)->not->toHaveKey('staticAnalysis');
})->with(['phpunit', 'phpspec', 'codeception', 'testo']);

it('tells Pest the tests, the bounds and the mutators beside its own', function (): void {
    $told = toldOptions('pest', checked: true);

    expect($told['tests'] ?? null)->toBe(['tests'])
        ->and($told['mutators'] ?? null)->toBe([PlusToMinus::class])
        ->and($told)->toHaveKey(BoundsOptions::TIMEOUT)
        ->and($told)->not->toHaveKey('staticAnalysis');
});
