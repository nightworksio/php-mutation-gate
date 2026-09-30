<?php

declare(strict_types=1);

use Infection\Mutator\ProfileList;
use NightWorksIO\MutationGate\Adapter\Infection\Families;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;

it('gives every mutator of the installed Infection a family, or marks it as having none', function (): void {
    $names = array_keys(ProfileList::ALL_MUTATORS);
    $unmapped = array_filter($names, static fn(string $mutator): bool => ! Families::knows($mutator));

    expect($names)->not->toBe([])
        ->and(array_values($unmapped))->toBe([]);
});

it('names a mutator\'s family by the name Infection\'s logs give it', function (): void {
    expect(Families::of('Plus'))->toBe(MutatorFamily::Arithmetic)
        ->and(Families::of('GreaterThan'))->toBe(MutatorFamily::Boundary)
        ->and(Families::of('Identical'))->toBe(MutatorFamily::Condition)
        ->and(Families::of('LogicalAnd'))->toBe(MutatorFamily::Logical)
        ->and(Families::of('TrueValue'))->toBe(MutatorFamily::Literal)
        ->and(Families::of('FunctionCall'))->toBe(MutatorFamily::ReturnValue)
        ->and(Families::of('MethodCallRemoval'))->toBe(MutatorFamily::RemovedCall)
        ->and(Families::of('Foreach_'))->toBe(MutatorFamily::Collection)
        ->and(Families::of('Throw_'))->toBe(MutatorFamily::Exception)
        ->and(Families::of('UnwrapTrim'))->toBe(MutatorFamily::Unwrap)
        ->and(Families::of('PublicVisibility'))->toBe(MutatorFamily::Visibility);
});

it('marks the mutators no hint can name as having no family', function (): void {
    foreach (['Concat', 'ConcatOperandRemoval', 'SyntaxError'] as $mutator) {
        expect(Families::of($mutator))->toBe(MutatorFamily::None)
            ->and(Families::knows($mutator))->toBeTrue();
    }
});

it('gives a mutator it does not know an unknown family', function (): void {
    expect(Families::of('FutureMutator'))->toBe(MutatorFamily::Unknown)
        ->and(Families::knows('FutureMutator'))->toBeFalse();
});
