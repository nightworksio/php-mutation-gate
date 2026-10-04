<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Registry\EnabledMutators;
use NightWorksIO\MutationGate\Cli\Registry\Lookup;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Mutators;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\PresetSet;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGate\Tests\Support\Mutators\DecrementToIncrement;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\Mutators\RemoveEcho;
use NightWorksIO\MutationGate\Tests\Support\Mutators\RemoveItem;

/** A registry with a `default` set and two others. */
function enablingLookup(): Lookup
{
    return Lookup::in(new Extensions(Origin::of('acme/gate'))
        ->withMutators(MutatorSet::defaultName(), MutatorSet::of(DecrementToIncrement::class))
        ->withMutators(Name::of('acme'), MutatorSet::of(PlusToMinus::class, RemoveEcho::class))
        ->withMutators(Name::of('acme-lists'), MutatorSet::of(RemoveItem::class)));
}

/** @return list<class-string<Mutator>> */
function enabledClasses(Enabled $enabled): array
{
    return array_map(static fn(Mutator $mutator): string => $mutator::class, [...$enabled]);
}

it('turns on the mutators of each set the config names, less those it turns off', function (): void {
    $enabled = EnabledMutators::in(enablingLookup(), Mutators::of(Listed::of('acme', 'acme-lists'), Listed::of('acme/RemoveEcho')));

    expect($enabled instanceof EnabledMutators ? enabledClasses($enabled->besideTheRunners()) : $enabled)
        ->toBe([PlusToMinus::class, RemoveItem::class])
        ->and($enabled instanceof EnabledMutators ? enabledClasses($enabled->forTheEngine()) : $enabled)
        ->toBe([DecrementToIncrement::class, PlusToMinus::class, RemoveItem::class]);
});

it('cannot judge a mutator to turn off that no set the config turns on holds, naming the one most likely meant', function (): void {
    expect(EnabledMutators::in(enablingLookup(), Mutators::of(Listed::of('acme'), Listed::of('acme/RemoveEcho', 'acme/RemoveEco'))))
        ->toEqual(CannotJudge::because('mutators.except names "acme/RemoveEco", which neither a mutator set in mutators.sets nor the default set holds. Did you mean "acme/RemoveEcho"?'));
});

it('turns off a mutator of the default set for the engine, under the PHPUnit runner or none named', function (BuiltinRunner|NotGiven $runner): void {
    $enabled = EnabledMutators::in(enablingLookup(), Mutators::of(except: Listed::of('acme/DecrementToIncrement')), $runner);

    expect($enabled instanceof EnabledMutators ? count($enabled->forTheEngine()) : $enabled)->toBe(0)
        ->and($enabled instanceof EnabledMutators ? count($enabled->besideTheRunners()) : $enabled)->toBe(0);
})->with([
    'the PHPUnit runner' => [BuiltinRunner::PhpUnit],
    'no built-in runner' => [NotGiven::value()],
]);

it('refuses a mutator to turn off that only the default set holds under a runner that runs its own', function (BuiltinRunner $runner): void {
    expect(EnabledMutators::in(enablingLookup(), Mutators::of(Listed::of('acme'), Listed::of('acme/RemoveEcho', 'acme/DecrementToIncrement')), $runner))
        ->toEqual(Invalid::because(Problem::at(
            'mutators.except',
            sprintf("expected a mutator of a set in mutators.sets, got \"acme/DecrementToIncrement\": the %s runner runs its own mutators in place of the default set's", $runner->value),
        )));
})->with([
    'Pest' => [BuiltinRunner::Pest],
    'Infection' => [BuiltinRunner::Infection],
]);

it('turns off a mutator of a set the config turns on under a runner that runs its own', function (): void {
    $enabled = EnabledMutators::in(enablingLookup(), Mutators::of(Listed::of('acme'), Listed::of('acme/RemoveEcho')), BuiltinRunner::Pest);

    expect($enabled instanceof EnabledMutators ? enabledClasses($enabled->besideTheRunners()) : $enabled)->toBe([PlusToMinus::class]);
});

it('names the default set\'s mutator most likely meant by a mutator to turn off that no set holds', function (): void {
    expect(EnabledMutators::in(enablingLookup(), Mutators::of(except: Listed::of('acme/DecrementToIncremnt'))))
        ->toEqual(CannotJudge::because('mutators.except names "acme/DecrementToIncremnt", which neither a mutator set in mutators.sets nor the default set holds. Did you mean "acme/DecrementToIncrement"?'));
});

it('cannot judge a mutator to turn off that no set holds, naming none where nothing is close', function (): void {
    expect(EnabledMutators::in(enablingLookup(), Mutators::of(Listed::of('acme'), Listed::of('acme/SwapArguments'))))
        ->toEqual(CannotJudge::because('mutators.except names "acme/SwapArguments", which neither a mutator set in mutators.sets nor the default set holds.'));
});

it('cannot judge a set nobody registered, naming the one most likely meant', function (): void {
    expect(EnabledMutators::in(enablingLookup(), Mutators::of(Listed::of('acme', 'acme-list'))))
        ->toEqual(CannotJudge::because('No mutator set is registered as "acme-list". Did you mean "acme-lists"?'));
});

it('gives the engine only the sets the config names where no default set is registered', function (): void {
    $lookup = Lookup::in(new Extensions(Origin::of('acme/gate'))->withMutators(Name::of('acme'), MutatorSet::of(PlusToMinus::class)));
    $enabled = EnabledMutators::in($lookup, Mutators::of(Listed::of('acme')));

    expect($enabled instanceof EnabledMutators ? enabledClasses($enabled->forTheEngine()) : $enabled)->toBe([PlusToMinus::class]);
});

it('skips a set a preset offers that nobody registered, saying which package registers it', function (): void {
    $offered = Mutators::offered(
        PresetSet::of(Name::of('acme'), Name::of('laravel'), 'acme/mutation-gate-acme'),
        PresetSet::of(Name::of('laravel'), Name::of('laravel'), 'nightworksio/mutation-gate-laravel'),
    );
    $enabled = EnabledMutators::in(enablingLookup(), $offered);

    expect($enabled instanceof EnabledMutators ? enabledClasses($enabled->besideTheRunners()) : $enabled)
        ->toBe([PlusToMinus::class, RemoveEcho::class])
        ->and($enabled instanceof EnabledMutators ? $enabled->skipped() : $enabled)->toEqual(Warnings::of(Warning::that(
            'The laravel preset turns on the mutator set "laravel", which is not installed: `composer require --dev nightworksio/mutation-gate-laravel`',
        )));
});

it('cannot judge a set nobody registered that a layer chooses, though a preset offers it too', function (): void {
    $chosen = Mutators::offered(PresetSet::of(Name::of('laravel'), Name::of('laravel'), 'nightworksio/mutation-gate-laravel'))
        ->over(Mutators::of(Listed::of('laravel')));

    expect(EnabledMutators::in(enablingLookup(), $chosen))
        ->toEqual(CannotJudge::because('No mutator set is registered as "laravel".'))
        ->and(EnabledMutators::in(enablingLookup(), Mutators::none()))->toEqual(EnabledMutators::in(enablingLookup(), Mutators::standard()));
});

it('skips nothing where every set is registered', function (): void {
    $enabled = EnabledMutators::in(enablingLookup(), Mutators::of(Listed::of('acme')));

    expect($enabled instanceof EnabledMutators ? $enabled->skipped() : $enabled)->toEqual(Warnings::none());
});
