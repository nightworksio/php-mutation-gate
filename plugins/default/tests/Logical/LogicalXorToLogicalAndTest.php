<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\Logical\LogicalXorToLogicalAnd;
use PhpParser\Node\Stmt\Nop;

it('is named in the default set, in its family, untagged, with the hint of its family', function (): void {
    $mutator = new LogicalXorToLogicalAnd();

    expect($mutator->name()->value())->toBe('default/LogicalXorToLogicalAnd')
        ->and($mutator->family())->toBe(MutatorFamily::Logical)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(FamilyHint::ofItsFamily());
});

it('changes the code it is written for, as Pest does', function (): void {
    $code = <<<'PHP'
        <?php

        return $a xor $b;
        PHP;

    expect(iterator_to_array(Mutates::with(new LogicalXorToLogicalAnd(), $code)->underPest(), preserve_keys: false))->toBe([
        "-return \$a xor \$b;\n+return \$a and \$b;",
    ]);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new LogicalXorToLogicalAnd()->mutate(new Nop()))->toEqual(Unchanged::node());
});
