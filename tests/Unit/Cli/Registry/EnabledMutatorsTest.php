<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Registry\EnabledMutators;
use NightWorksIO\MutationGate\Cli\Registry\Lookup;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Mutators;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Registry\Origin;
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

it('turns off a mutator of the default set for the engine', function (): void {
    $enabled = EnabledMutators::in(enablingLookup(), Mutators::of(except: Listed::of('acme/DecrementToIncrement')));

    expect($enabled instanceof EnabledMutators ? count($enabled->forTheEngine()) : $enabled)->toBe(0)
        ->and($enabled instanceof EnabledMutators ? count($enabled->besideTheRunners()) : $enabled)->toBe(0);
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
