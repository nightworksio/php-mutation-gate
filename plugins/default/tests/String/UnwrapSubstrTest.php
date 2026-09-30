<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\String\UnwrapSubstr;
use PhpParser\Node\Stmt\Nop;

it('is named in the default set, in its family, untagged, with the hint of its family', function (): void {
    $mutator = new UnwrapSubstr();

    expect($mutator->name()->value())->toBe('default/UnwrapSubstr')
        ->and($mutator->family())->toBe(MutatorFamily::Unwrap)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(FamilyHint::ofItsFamily());
});

it('changes the code it is written for, as Pest does', function (): void {
    $code = <<<'PHP'
        <?php

        return substr($a, $b, $c);
        return \substr($a, $b, $c);
        return substr(...);
        PHP;

    expect(iterator_to_array(Mutates::with(new UnwrapSubstr(), $code)->underPest(), preserve_keys: false))->toBe([
        "-return substr(\$a, \$b, \$c);\n+return \$a;",
        "-return \\substr(\$a, \$b, \$c);\n+return \$a;",
        "-return substr(...);\n+return fn(\$value) => \$value;",
    ]);
});

it('leaves alone the code it is not written for', function (): void {
    $code = <<<'PHP'
        <?php

        return Acme\substr($a, $b, $c);
        return $a->substr($b);
        PHP;

    expect(Mutates::with(new UnwrapSubstr(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new UnwrapSubstr()->mutate(new Nop()))->toEqual(Unchanged::node());
});
