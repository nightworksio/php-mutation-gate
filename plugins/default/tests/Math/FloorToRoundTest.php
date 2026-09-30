<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\Math\FloorToRound;
use PhpParser\Node\Stmt\Nop;

it('is named in the default set, in its family, untagged, with the hint of its family', function (): void {
    $mutator = new FloorToRound();

    expect($mutator->name()->value())->toBe('default/FloorToRound')
        ->and($mutator->family())->toBe(MutatorFamily::Arithmetic)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(FamilyHint::ofItsFamily());
});

it('changes the code it is written for, as Pest does', function (): void {
    $code = <<<'PHP'
        <?php

        return floor($a);
        return \floor($a);
        PHP;

    expect(iterator_to_array(Mutates::with(new FloorToRound(), $code)->underPest(), preserve_keys: false))->toBe([
        "-return floor(\$a);\n+return round(\$a);",
        "-return \\floor(\$a);\n+return round(\$a);",
    ]);
});

it('leaves alone the code it is not written for', function (): void {
    $code = <<<'PHP'
        <?php

        return Acme\floor($a);
        PHP;

    expect(Mutates::with(new FloorToRound(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new FloorToRound()->mutate(new Nop()))->toEqual(Unchanged::node());
});
