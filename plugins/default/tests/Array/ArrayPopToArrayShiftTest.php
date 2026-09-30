<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\Array\ArrayPopToArrayShift;
use PhpParser\Node\Stmt\Nop;

it('is named in the default set, in its family, untagged, with the hint of its family', function (): void {
    $mutator = new ArrayPopToArrayShift();

    expect($mutator->name()->value())->toBe('default/ArrayPopToArrayShift')
        ->and($mutator->family())->toBe(MutatorFamily::Collection)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(FamilyHint::ofItsFamily());
});

it('changes the code it is written for, as Pest does', function (): void {
    $code = <<<'PHP'
        <?php

        return array_pop($a);
        return \array_pop($a);
        PHP;

    expect(iterator_to_array(Mutates::with(new ArrayPopToArrayShift(), $code)->underPest(), preserve_keys: false))->toBe([
        "-return array_pop(\$a);\n+return array_shift(\$a);",
        "-return \\array_pop(\$a);\n+return array_shift(\$a);",
    ]);
});

it('leaves alone the code it is not written for', function (): void {
    $code = <<<'PHP'
        <?php

        return Acme\array_pop($a);
        PHP;

    expect(Mutates::with(new ArrayPopToArrayShift(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new ArrayPopToArrayShift()->mutate(new Nop()))->toEqual(Unchanged::node());
});
