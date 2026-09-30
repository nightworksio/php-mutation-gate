<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\Math\RoundToCeil;
use PhpParser\Node\Stmt\Nop;

it('is named in the default set, in its family, untagged, with the hint of its family', function (): void {
    $mutator = new RoundToCeil();

    expect($mutator->name()->value())->toBe('default/RoundToCeil')
        ->and($mutator->family())->toBe(MutatorFamily::Arithmetic)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(FamilyHint::ofItsFamily());
});

it('changes the code it is written for, as Pest does', function (): void {
    $code = <<<'PHP'
        <?php

        return round($a);
        return \round($a);
        PHP;

    expect(iterator_to_array(Mutates::with(new RoundToCeil(), $code)->underPest(), preserve_keys: false))->toBe([
        "-return round(\$a);\n+return ceil(\$a);",
        "-return \\round(\$a);\n+return ceil(\$a);",
    ]);
});

it('leaves alone the code it is not written for', function (): void {
    $code = <<<'PHP'
        <?php

        return Acme\round($a);
        PHP;

    expect(Mutates::with(new RoundToCeil(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new RoundToCeil()->mutate(new Nop()))->toEqual(Unchanged::node());
});
