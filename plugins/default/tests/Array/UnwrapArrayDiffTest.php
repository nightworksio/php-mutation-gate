<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayDiff;
use PhpParser\Node\Stmt\Nop;

it('is named in the default set, in its family, untagged, with the hint of its family', function (): void {
    $mutator = new UnwrapArrayDiff();

    expect($mutator->name()->value())->toBe('default/UnwrapArrayDiff')
        ->and($mutator->family())->toBe(MutatorFamily::Unwrap)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(FamilyHint::ofItsFamily());
});

it('changes the code it is written for, as Pest does', function (): void {
    $code = <<<'PHP'
        <?php

        return array_diff($a, $b, $c);
        return \array_diff($a, $b, $c);
        return array_diff(...);
        PHP;

    expect(iterator_to_array(Mutates::with(new UnwrapArrayDiff(), $code)->underPest(), preserve_keys: false))->toBe([
        "-return array_diff(\$a, \$b, \$c);\n+return \$a;",
        "-return \\array_diff(\$a, \$b, \$c);\n+return \$a;",
        "-return array_diff(...);\n+return fn(\$value) => \$value;",
    ]);
});

it('leaves alone the code it is not written for', function (): void {
    $code = <<<'PHP'
        <?php

        return Acme\array_diff($a, $b, $c);
        return $a->array_diff($b);
        PHP;

    expect(Mutates::with(new UnwrapArrayDiff(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new UnwrapArrayDiff()->mutate(new Nop()))->toEqual(Unchanged::node());
});
