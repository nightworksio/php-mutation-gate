<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\Number\IncrementInteger;
use PhpParser\Node\Stmt\Nop;

it('is named in the default set, in its family, untagged, with the hint of its family', function (): void {
    $mutator = new IncrementInteger();

    expect($mutator->name()->value())->toBe('default/IncrementInteger')
        ->and($mutator->family())->toBe(MutatorFamily::Literal)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(FamilyHint::ofItsFamily());
});

it('changes the code it is written for, as Pest does', function (): void {
    $code = <<<'PHP'
        <?php

        return 3;
        return -3;
        PHP;

    expect(iterator_to_array(Mutates::with(new IncrementInteger(), $code)->underPest(), preserve_keys: false))->toBe([
        "-return 3;\n+return 4;",
        "-return -3;\n+return -2;",
    ]);
});

it('leaves alone the code it is not written for', function (): void {
    $code = <<<'PHP'
        <?php

        return 9223372036854775807;
        PHP;

    expect(Mutates::with(new IncrementInteger(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new IncrementInteger()->mutate(new Nop()))->toEqual(Unchanged::node());
});
