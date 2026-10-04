<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\Mutators\RemoveEcho;
use NightWorksIO\MutationGate\Tests\Support\Mutators\RemoveItem;

it('constructs each of a set\'s mutators, but those turned off by name', function (): void {
    $enabled = Enabled::of(MutatorSet::of(PlusToMinus::class, RemoveEcho::class, RemoveItem::class), 'acme/RemoveEcho', 'acme/Absent');

    expect(count($enabled))->toBe(2)
        ->and(array_map(static fn(Mutator $mutator): string => $mutator::class, [...$enabled]))
        ->toBe([PlusToMinus::class, RemoveItem::class])
        ->and([...$enabled->classes()])->toBe([PlusToMinus::class, RemoveItem::class]);
});

it('makes mutants with the mutators it holds', function (): void {
    expect(Enabled::of(MutatorSet::of(PlusToMinus::class))->engine())->toEqual(Engine::with(new PlusToMinus()));
});

it('takes the classes a runner\'s options name, and refuses one that is not a mutator', function (): void {
    $named = Enabled::named(BuiltinRunner::Pest, PlusToMinus::class);

    expect($named instanceof Enabled ? count($named) : $named)->toBe(1)
        ->and(Enabled::named(BuiltinRunner::Infection, PlusToMinus::class, stdClass::class))->toEqual(
            CannotJudge::because('The infection runner cannot make mutants with stdClass, which is not a mutator.'),
        );
});
