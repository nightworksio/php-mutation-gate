<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayPad;
use PhpParser\Node\Stmt\Nop;

it('is named in the default set, in its family, untagged, with the hint of its family', function (): void {
    $mutator = new UnwrapArrayPad();

    expect($mutator->name()->value())->toBe('default/UnwrapArrayPad')
        ->and($mutator->family())->toBe(MutatorFamily::Unwrap)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(FamilyHint::ofItsFamily());
});

it('changes the code it is written for, as Pest does', function (): void {
    $code = <<<'PHP'
        <?php

        return array_pad($a, $b, $c);
        return \array_pad($a, $b, $c);
        return array_pad(...);
        PHP;

    expect(iterator_to_array(Mutates::with(new UnwrapArrayPad(), $code)->underPest(), preserve_keys: false))->toBe([
        "-return array_pad(\$a, \$b, \$c);\n+return \$a;",
        "-return \\array_pad(\$a, \$b, \$c);\n+return \$a;",
        "-return array_pad(...);\n+return fn(\$value) => \$value;",
    ]);
});

it('leaves alone the code it is not written for', function (): void {
    $code = <<<'PHP'
        <?php

        return Acme\array_pad($a, $b, $c);
        return $a->array_pad($b);
        PHP;

    expect(Mutates::with(new UnwrapArrayPad(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new UnwrapArrayPad()->mutate(new Nop()))->toEqual(Unchanged::node());
});
