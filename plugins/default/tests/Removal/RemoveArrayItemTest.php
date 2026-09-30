<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\Removal\RemoveArrayItem;
use PhpParser\Node\Stmt\Nop;

it('is named in the default set, in its family, untagged, with the hint of its family', function (): void {
    $mutator = new RemoveArrayItem();

    expect($mutator->name()->value())->toBe('default/RemoveArrayItem')
        ->and($mutator->family())->toBe(MutatorFamily::Collection)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(FamilyHint::ofItsFamily());
});

it('changes the code it is written for, as Pest does', function (): void {
    $code = <<<'PHP'
        <?php

        return [1, 2];
        PHP;

    expect(iterator_to_array(Mutates::with(new RemoveArrayItem(), $code)->underPest(), preserve_keys: false))->toBe([
        "-return [1, 2];\n+return [2];",
        "-return [1, 2];\n+return [1];",
    ]);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new RemoveArrayItem()->mutate(new Nop()))->toEqual(Unchanged::node());
});
