<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\Removal\RemoveMethodCall;
use PhpParser\Node\Stmt\Nop;

it('is named in the default set, in its family, untagged, with the hint of its family', function (): void {
    $mutator = new RemoveMethodCall();

    expect($mutator->name()->value())->toBe('default/RemoveMethodCall')
        ->and($mutator->family())->toBe(MutatorFamily::RemovedCall)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(FamilyHint::ofItsFamily());
});

it('changes the code it is written for, as Pest does', function (): void {
    $code = <<<'PHP'
        <?php

        $a->b();
        A::b();
        PHP;

    expect(iterator_to_array(Mutates::with(new RemoveMethodCall(), $code)->underPest(), preserve_keys: false))->toBe([
        "-\$a->b();",
        "-A::b();",
    ]);
});

it('leaves alone the code it is not written for', function (): void {
    $code = <<<'PHP'
        <?php

        $c = $a->b();
        PHP;

    expect(Mutates::with(new RemoveMethodCall(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new RemoveMethodCall()->mutate(new Nop()))->toEqual(Unchanged::node());
});
