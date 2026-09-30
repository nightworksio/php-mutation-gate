<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\String\UnwrapLtrim;
use PhpParser\Node\Stmt\Nop;

it('is named in the default set, in its family, untagged, with the hint of its family', function (): void {
    $mutator = new UnwrapLtrim();

    expect($mutator->name()->value())->toBe('default/UnwrapLtrim')
        ->and($mutator->family())->toBe(MutatorFamily::Unwrap)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(FamilyHint::ofItsFamily());
});

it('changes the code it is written for, as Pest does', function (): void {
    $code = <<<'PHP'
        <?php

        return ltrim($a, $b, $c);
        return \ltrim($a, $b, $c);
        return ltrim(...);
        PHP;

    expect(iterator_to_array(Mutates::with(new UnwrapLtrim(), $code)->underPest(), preserve_keys: false))->toBe([
        "-return ltrim(\$a, \$b, \$c);\n+return \$a;",
        "-return \\ltrim(\$a, \$b, \$c);\n+return \$a;",
        "-return ltrim(...);\n+return fn(\$value) => \$value;",
    ]);
});

it('leaves alone the code it is not written for', function (): void {
    $code = <<<'PHP'
        <?php

        return Acme\ltrim($a, $b, $c);
        return $a->ltrim($b);
        PHP;

    expect(Mutates::with(new UnwrapLtrim(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new UnwrapLtrim()->mutate(new Nop()))->toEqual(Unchanged::node());
});
