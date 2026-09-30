<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\Math\CeilToRound;
use PhpParser\Node\Stmt\Nop;

it('is named in the default set, in its family, untagged, with the hint of its family', function (): void {
    $mutator = new CeilToRound();

    expect($mutator->name()->value())->toBe('default/CeilToRound')
        ->and($mutator->family())->toBe(MutatorFamily::Arithmetic)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(FamilyHint::ofItsFamily());
});

it('changes the code it is written for, as Pest does', function (): void {
    $code = <<<'PHP'
        <?php

        return ceil($a);
        return \ceil($a);
        PHP;

    expect(iterator_to_array(Mutates::with(new CeilToRound(), $code)->underPest(), preserve_keys: false))->toBe([
        "-return ceil(\$a);\n+return round(\$a);",
        "-return \\ceil(\$a);\n+return round(\$a);",
    ]);
});

it('leaves alone the code it is not written for', function (): void {
    $code = <<<'PHP'
        <?php

        return Acme\ceil($a);
        PHP;

    expect(Mutates::with(new CeilToRound(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new CeilToRound()->mutate(new Nop()))->toEqual(Unchanged::node());
});
