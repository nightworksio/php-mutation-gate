<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\Math\MaxToMin;
use PhpParser\Node\Stmt\Nop;

it('is named in the default set, in its family, untagged, with the hint of its family', function (): void {
    $mutator = new MaxToMin();

    expect($mutator->name()->value())->toBe('default/MaxToMin')
        ->and($mutator->family())->toBe(MutatorFamily::Boundary)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(FamilyHint::ofItsFamily());
});

it('changes the code it is written for, as Pest does', function (): void {
    $code = <<<'PHP'
        <?php

        return max($a);
        return \max($a);
        PHP;

    expect(iterator_to_array(Mutates::with(new MaxToMin(), $code)->underPest(), preserve_keys: false))->toBe([
        "-return max(\$a);\n+return min(\$a);",
        "-return \\max(\$a);\n+return min(\$a);",
    ]);
});

it('leaves alone the code it is not written for', function (): void {
    $code = <<<'PHP'
        <?php

        return Acme\max($a);
        PHP;

    expect(Mutates::with(new MaxToMin(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new MaxToMin()->mutate(new Nop()))->toEqual(Unchanged::node());
});
