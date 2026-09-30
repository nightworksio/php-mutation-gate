<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\Arithmetic\PostDecrementToPostIncrement;
use PhpParser\Node\Stmt\Nop;

it('is named in the default set, in its family, untagged, with the hint of its family', function (): void {
    $mutator = new PostDecrementToPostIncrement();

    expect($mutator->name()->value())->toBe('default/PostDecrementToPostIncrement')
        ->and($mutator->family())->toBe(MutatorFamily::Arithmetic)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(FamilyHint::ofItsFamily());
});

it('changes the code it is written for, as Pest does', function (): void {
    $code = <<<'PHP'
        <?php

        $a--;
        PHP;

    expect(iterator_to_array(Mutates::with(new PostDecrementToPostIncrement(), $code)->underPest(), preserve_keys: false))->toBe([
        "-\$a--;\n+\$a++;",
    ]);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new PostDecrementToPostIncrement()->mutate(new Nop()))->toEqual(Unchanged::node());
});
