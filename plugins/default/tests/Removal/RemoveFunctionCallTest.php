<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\Removal\RemoveFunctionCall;
use PhpParser\Node\Stmt\Nop;

it('is named in the default set, in its family, untagged, with the hint of its family', function (): void {
    $mutator = new RemoveFunctionCall();

    expect($mutator->name()->value())->toBe('default/RemoveFunctionCall')
        ->and($mutator->family())->toBe(MutatorFamily::RemovedCall)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(FamilyHint::ofItsFamily());
});

it('changes the code it is written for, as Pest does', function (): void {
    $code = <<<'PHP'
        <?php

        strlen($a);
        PHP;

    expect(iterator_to_array(Mutates::with(new RemoveFunctionCall(), $code)->underPest(), preserve_keys: false))->toBe([
        "-strlen(\$a);",
    ]);
});

it('leaves alone the code it is not written for', function (): void {
    $code = <<<'PHP'
        <?php

        $b = strlen($a);
        PHP;

    expect(Mutates::with(new RemoveFunctionCall(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new RemoveFunctionCall()->mutate(new Nop()))->toEqual(Unchanged::node());
});
