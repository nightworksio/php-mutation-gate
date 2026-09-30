<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\String\StrStartsWithToStrEndsWith;
use PhpParser\Node\Stmt\Nop;

it('is named in the default set, in its family, untagged, with the hint of its family', function (): void {
    $mutator = new StrStartsWithToStrEndsWith();

    expect($mutator->name()->value())->toBe('default/StrStartsWithToStrEndsWith')
        ->and($mutator->family())->toBe(MutatorFamily::Condition)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(FamilyHint::ofItsFamily());
});

it('changes the code it is written for, as Pest does', function (): void {
    $code = <<<'PHP'
        <?php

        return str_starts_with($a);
        return \str_starts_with($a);
        PHP;

    expect(iterator_to_array(Mutates::with(new StrStartsWithToStrEndsWith(), $code)->underPest(), preserve_keys: false))->toBe([
        "-return str_starts_with(\$a);\n+return str_ends_with(\$a);",
        "-return \\str_starts_with(\$a);\n+return str_ends_with(\$a);",
    ]);
});

it('leaves alone the code it is not written for', function (): void {
    $code = <<<'PHP'
        <?php

        return Acme\str_starts_with($a);
        PHP;

    expect(Mutates::with(new StrStartsWithToStrEndsWith(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new StrStartsWithToStrEndsWith()->mutate(new Nop()))->toEqual(Unchanged::node());
});
