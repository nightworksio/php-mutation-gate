<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Mutant\NamedMutators;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinusAlso;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinusToo;
use NightWorksIO\MutationGate\Tests\Support\Mutators\RemoveEcho;
use NightWorksIO\MutationGate\Tests\Support\Mutators\RemoveItem;
use NightWorksIO\MutationGateDefault\String\UnwrapHtmlentities as DefaultUnwrapHtmlentities;
use NightWorksIO\MutationGateDefault\String\UnwrapHtmlspecialchars as DefaultUnwrapHtmlspecialchars;
use NightWorksIO\MutationGateDefault\String\UnwrapStripTags as DefaultUnwrapStripTags;
use NightWorksIO\MutationGateSecurity\Mutators\UnwrapHtmlentities;
use NightWorksIO\MutationGateSecurity\Mutators\UnwrapHtmlspecialchars;
use NightWorksIO\MutationGateSecurity\Mutators\UnwrapStripTags;

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

it('stands a mutator down beside one it names that makes its change and names none', function (): void {
    $enabled = Enabled::of(MutatorSet::of(PlusToMinusToo::class, RemoveEcho::class, PlusToMinus::class));

    expect([...$enabled->besides(NamedMutators::of())->classes()])->toBe([RemoveEcho::class, PlusToMinus::class])
        ->and([...Enabled::of(MutatorSet::of(PlusToMinusToo::class, RemoveEcho::class))->besides(NamedMutators::of())->classes()])
        ->toBe([PlusToMinusToo::class, RemoveEcho::class]);
});

it('stands a mutator down beside a runner\'s own that it names, and no other', function (): void {
    $enabled = Enabled::of(MutatorSet::of(PlusToMinusToo::class, RemoveEcho::class));

    expect([...$enabled->besides(NamedMutators::of('Plus'))->classes()])->toBe([RemoveEcho::class])
        ->and([...$enabled->besides(NamedMutators::of('Minus'))->classes()])->toBe([PlusToMinusToo::class, RemoveEcho::class]);
});

it('keeps both of two mutators that name each other', function (): void {
    $enabled = Enabled::of(MutatorSet::of(PlusToMinusToo::class, PlusToMinusAlso::class));

    expect([...$enabled->besides(NamedMutators::of())->classes()])->toBe([PlusToMinusToo::class, PlusToMinusAlso::class]);
});

it('stands each of the security set\'s HTML unwraps down beside the default set\'s, one by one', function (): void {
    $security = [UnwrapHtmlspecialchars::class, UnwrapHtmlentities::class, UnwrapStripTags::class];
    $default = [DefaultUnwrapHtmlspecialchars::class, DefaultUnwrapHtmlentities::class, DefaultUnwrapStripTags::class];

    expect([...Enabled::of(MutatorSet::of(...$security, ...$default))->besides(NamedMutators::of())->classes()])->toBe($default)
        ->and([...Enabled::of(MutatorSet::of(...[...$security, DefaultUnwrapStripTags::class]))->besides(NamedMutators::of())->classes()])
        ->toBe([UnwrapHtmlspecialchars::class, UnwrapHtmlentities::class, DefaultUnwrapStripTags::class]);
});
