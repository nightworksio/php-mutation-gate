<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\String\ConcatSwitchSides;
use PhpParser\Node\Stmt\Nop;

it('is named in the default set, in its family, untagged, with the hint of its family', function (): void {
    $mutator = new ConcatSwitchSides();

    expect($mutator->name()->value())->toBe('default/ConcatSwitchSides')
        ->and($mutator->family())->toBe(MutatorFamily::None)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(FamilyHint::ofItsFamily());
});

it('changes the code it is written for, as Pest does', function (): void {
    $code = <<<'PHP'
        <?php

        return $a . $b;
        return 'a' . 'b';
        return A . B;
        return $a . 'b';
        PHP;

    expect(iterator_to_array(Mutates::with(new ConcatSwitchSides(), $code)->underPest(), preserve_keys: false))->toBe([
        "-return \$a . \$b;\n+return \$b . \$a;",
        "-return 'a' . 'b';\n+return 'b' . 'a';",
        "-return A . B;\n+return B . A;",
        "-return \$a . 'b';\n+return 'b' . \$a;",
    ]);
});

it('leaves alone the code it is not written for', function (): void {
    $code = <<<'PHP'
        <?php

        return $a . $a;
        return 'a' . 'a';
        return A . A;
        PHP;

    expect(Mutates::with(new ConcatSwitchSides(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new ConcatSwitchSides()->mutate(new Nop()))->toEqual(Unchanged::node());
});
