<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayDiffUassoc;
use PhpParser\Node\Stmt\Nop;

it('is named in the default set, in its family, untagged, with the hint of its family', function (): void {
    $mutator = new UnwrapArrayDiffUassoc();

    expect($mutator->name()->value())->toBe('default/UnwrapArrayDiffUassoc')
        ->and($mutator->family())->toBe(MutatorFamily::Unwrap)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(FamilyHint::ofItsFamily());
});

it('changes the code it is written for, as Pest does', function (): void {
    $code = <<<'PHP'
        <?php

        return array_diff_uassoc($a, $b, $c);
        return \array_diff_uassoc($a, $b, $c);
        return array_diff_uassoc(...);
        PHP;

    expect(iterator_to_array(Mutates::with(new UnwrapArrayDiffUassoc(), $code)->underPest(), preserve_keys: false))->toBe([
        "-return array_diff_uassoc(\$a, \$b, \$c);\n+return \$a;",
        "-return \\array_diff_uassoc(\$a, \$b, \$c);\n+return \$a;",
        "-return array_diff_uassoc(...);\n+return fn(\$value) => \$value;",
    ]);
});

it('leaves alone the code it is not written for', function (): void {
    $code = <<<'PHP'
        <?php

        return Acme\array_diff_uassoc($a, $b, $c);
        return $a->array_diff_uassoc($b);
        PHP;

    expect(Mutates::with(new UnwrapArrayDiffUassoc(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new UnwrapArrayDiffUassoc()->mutate(new Nop()))->toEqual(Unchanged::node());
});
