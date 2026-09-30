<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\Exception\RemoveThrow;
use PhpParser\Node\Stmt\Nop;

it('is named in the default set, in its family, untagged, with the hint of its family', function (): void {
    $mutator = new RemoveThrow();

    expect($mutator->name()->value())->toBe('default/RemoveThrow')
        ->and($mutator->family())->toBe(MutatorFamily::Exception)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(FamilyHint::ofItsFamily());
});

it('changes the code it is written for', function (): void {
    $code = <<<'PHP'
        <?php

        if ($a) {
            throw new RuntimeException('no');
        }
        PHP;

    expect(iterator_to_array(Mutates::with(new RemoveThrow(), $code)->underPest(), preserve_keys: false))->toBe([
        "-    throw new RuntimeException('no');",
    ]);
});

it('leaves alone the code it is not written for', function (): void {
    $code = <<<'PHP'
        <?php

        $b = $a ?? throw new RuntimeException('no');
        PHP;

    expect(Mutates::with(new RemoveThrow(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new RemoveThrow()->mutate(new Nop()))->toEqual(Unchanged::node());
});
