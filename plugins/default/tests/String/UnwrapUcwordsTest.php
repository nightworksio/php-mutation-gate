<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\String\UnwrapUcwords;
use PhpParser\Node\Stmt\Nop;

it('is named in the default set, in its family, untagged, with the hint of its family', function (): void {
    $mutator = new UnwrapUcwords();

    expect($mutator->name()->value())->toBe('default/UnwrapUcwords')
        ->and($mutator->family())->toBe(MutatorFamily::Unwrap)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(FamilyHint::ofItsFamily());
});

it('changes the code it is written for, as Pest does', function (): void {
    $code = <<<'PHP'
        <?php

        return ucwords($a, $b, $c);
        return \ucwords($a, $b, $c);
        return ucwords(...);
        PHP;

    expect(iterator_to_array(Mutates::with(new UnwrapUcwords(), $code)->underPest(), preserve_keys: false))->toBe([
        "-return ucwords(\$a, \$b, \$c);\n+return \$a;",
        "-return \\ucwords(\$a, \$b, \$c);\n+return \$a;",
        "-return ucwords(...);\n+return fn(\$value) => \$value;",
    ]);
});

it('leaves alone the code it is not written for', function (): void {
    $code = <<<'PHP'
        <?php

        return Acme\ucwords($a, $b, $c);
        return $a->ucwords($b);
        PHP;

    expect(Mutates::with(new UnwrapUcwords(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new UnwrapUcwords()->mutate(new Nop()))->toEqual(Unchanged::node());
});
