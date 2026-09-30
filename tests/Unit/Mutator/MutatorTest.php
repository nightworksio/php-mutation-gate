<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Removal;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\Mutators\RemoveEcho;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Echo_;

it('describes itself by its name, family, tags and hint', function (): void {
    $mutator = new RemoveEcho();

    expect($mutator->name()->value())->toBe('acme/RemoveEcho')
        ->and($mutator->family())->toBe(MutatorFamily::RemovedCall)
        ->and($mutator->tags()->has(Tag::named('output')))->toBeTrue()
        ->and($mutator->hint()->sentence())->toBe('No test checks what is printed.')
        ->and($mutator->mutate(new Echo_([new Variable('a')])))->toEqual(Removal::statement());
});

it('gives its survivors its family\'s sentence where it has none of its own', function (): void {
    expect(new PlusToMinus()->hint())->toEqual(FamilyHint::ofItsFamily());
});
