<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\Array\ArrayKeyLastToArrayKeyFirst;
use PhpParser\Node\Stmt\Nop;

it('is named in the default set, in its family, untagged, with the hint of its family', function (): void {
    $mutator = new ArrayKeyLastToArrayKeyFirst();

    expect($mutator->name()->value())->toBe('default/ArrayKeyLastToArrayKeyFirst')
        ->and($mutator->family())->toBe(MutatorFamily::Collection)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(FamilyHint::ofItsFamily());
});

it('changes the code it is written for, as Pest does', function (): void {
    $code = <<<'PHP'
        <?php

        return array_key_last($a);
        return \array_key_last($a);
        PHP;

    expect(iterator_to_array(Mutates::with(new ArrayKeyLastToArrayKeyFirst(), $code)->underPest(), preserve_keys: false))->toBe([
        "-return array_key_last(\$a);\n+return array_key_first(\$a);",
        "-return \\array_key_last(\$a);\n+return array_key_first(\$a);",
    ]);
});

it('leaves alone the code it is not written for', function (): void {
    $code = <<<'PHP'
        <?php

        return Acme\array_key_last($a);
        PHP;

    expect(Mutates::with(new ArrayKeyLastToArrayKeyFirst(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new ArrayKeyLastToArrayKeyFirst()->mutate(new Nop()))->toEqual(Unchanged::node());
});
