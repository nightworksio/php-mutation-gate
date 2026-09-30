<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\String\UnwrapStrPad;
use PhpParser\Node\Stmt\Nop;

it('is named in the default set, in its family, untagged, with the hint of its family', function (): void {
    $mutator = new UnwrapStrPad();

    expect($mutator->name()->value())->toBe('default/UnwrapStrPad')
        ->and($mutator->family())->toBe(MutatorFamily::Unwrap)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(FamilyHint::ofItsFamily());
});

it('changes the code it is written for, as Pest does', function (): void {
    $code = <<<'PHP'
        <?php

        return str_pad($a, $b, $c);
        return \str_pad($a, $b, $c);
        return str_pad(...);
        PHP;

    expect(iterator_to_array(Mutates::with(new UnwrapStrPad(), $code)->underPest(), preserve_keys: false))->toBe([
        "-return str_pad(\$a, \$b, \$c);\n+return \$a;",
        "-return \\str_pad(\$a, \$b, \$c);\n+return \$a;",
        "-return str_pad(...);\n+return fn(\$value) => \$value;",
    ]);
});

it('leaves alone the code it is not written for', function (): void {
    $code = <<<'PHP'
        <?php

        return Acme\str_pad($a, $b, $c);
        return $a->str_pad($b);
        PHP;

    expect(Mutates::with(new UnwrapStrPad(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new UnwrapStrPad()->mutate(new Nop()))->toEqual(Unchanged::node());
});
