<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\Removal\RemoveNullSafeOperator;
use PhpParser\Node\Stmt\Nop;

it('is named in the default set, in its family, untagged, with the hint of its family', function (): void {
    $mutator = new RemoveNullSafeOperator();

    expect($mutator->name()->value())->toBe('default/RemoveNullSafeOperator')
        ->and($mutator->family())->toBe(MutatorFamily::Condition)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(FamilyHint::ofItsFamily());
});

it('changes the code it is written for, as Pest does', function (): void {
    $code = <<<'PHP'
        <?php

        return $a?->b;
        return $a?->c();
        PHP;

    expect(iterator_to_array(Mutates::with(new RemoveNullSafeOperator(), $code)->underPest(), preserve_keys: false))->toBe([
        "-return \$a?->b;\n+return \$a->b;",
        "-return \$a?->c();\n+return \$a->c();",
    ]);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new RemoveNullSafeOperator()->mutate(new Nop()))->toEqual(Unchanged::node());
});
