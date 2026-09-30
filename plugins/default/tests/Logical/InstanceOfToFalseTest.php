<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\Logical\InstanceOfToFalse;
use PhpParser\Node\Stmt\Nop;

it('is named in the default set, in its family, untagged, with the hint of its family', function (): void {
    $mutator = new InstanceOfToFalse();

    expect($mutator->name()->value())->toBe('default/InstanceOfToFalse')
        ->and($mutator->family())->toBe(MutatorFamily::Condition)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(FamilyHint::ofItsFamily());
});

it('changes the code it is written for, as Pest does', function (): void {
    $code = <<<'PHP'
        <?php

        return $a instanceof Money;
        PHP;

    expect(iterator_to_array(Mutates::with(new InstanceOfToFalse(), $code)->underPest(), preserve_keys: false))->toBe([
        "-return \$a instanceof Money;\n+return false;",
    ]);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new InstanceOfToFalse()->mutate(new Nop()))->toEqual(Unchanged::node());
});
