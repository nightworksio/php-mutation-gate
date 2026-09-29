<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Families;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Tests\Support\Tree;

/**
 * Every mutator class of the pest-plugin-mutate installed here, by its name.
 *
 * @return list<string>
 */
function pestMutators(): array
{
    $directory = Tree::at('vendor/pestphp/pest-plugin-mutate/src/Mutators');
    $found = [];

    foreach (Tree::filesUnder('vendor/pestphp/pest-plugin-mutate/src/Mutators') as $path) {
        $class = str_replace('/', '\\', mb_substr(Tree::at($path), mb_strlen($directory) + 1, -mb_strlen('.php')));

        if (preg_match('/^(Abstract|Concerns|Sets)\\\\/', $class) !== 1) {
            $found[] = sprintf('Pest\Mutate\Mutators\%s', $class);
        }
    }

    return $found;
}

it('gives every mutator of the supported pest-plugin-mutate a family, or marks it as having none', function (): void {
    $unmapped = array_filter(pestMutators(), static fn(string $mutator): bool => ! Families::knows($mutator));

    expect(pestMutators())->not->toBe([])
        ->and(array_values($unmapped))->toBe([]);
});

it('names a mutator\'s family by its class, whose short name another mutator may share', function (): void {
    expect(Families::of('Pest\Mutate\Mutators\Arithmetic\PlusToMinus'))->toBe(MutatorFamily::Arithmetic)
        ->and(Families::of('Pest\Mutate\Mutators\Equality\GreaterToGreaterOrEqual'))->toBe(MutatorFamily::Boundary)
        ->and(Families::of('Pest\Mutate\Mutators\Assignment\BitwiseAndToBitwiseOr'))->toBe(MutatorFamily::Arithmetic)
        ->and(Families::of('Pest\Mutate\Mutators\String\ConcatSwitchSides'))->toBe(MutatorFamily::None)
        ->and(Families::knows('Pest\Mutate\Mutators\String\ConcatSwitchSides'))->toBeTrue();
});

it('gives a mutator it does not know no family', function (): void {
    expect(Families::of('Pest\Mutate\Mutators\Future\Unknown'))->toBe(MutatorFamily::None)
        ->and(Families::knows('Pest\Mutate\Mutators\Future\Unknown'))->toBeFalse();
});
