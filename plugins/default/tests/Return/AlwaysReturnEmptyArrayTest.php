<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\Return\AlwaysReturnEmptyArray;
use PhpParser\Node\Stmt\Nop;

it('is named in the default set, in its family, untagged, with the hint of its family', function (): void {
    $mutator = new AlwaysReturnEmptyArray();

    expect($mutator->name()->value())->toBe('default/AlwaysReturnEmptyArray')
        ->and($mutator->family())->toBe(MutatorFamily::ReturnValue)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(FamilyHint::ofItsFamily());
});

it('changes the code it is written for, as Pest does', function (): void {
    $code = <<<'PHP'
        <?php

        function items(): array
        {
            return [1];
        }

        function some(): array|false
        {
            return [1];
        }
        PHP;

    expect(iterator_to_array(Mutates::with(new AlwaysReturnEmptyArray(), $code)->underPest(), preserve_keys: false))->toBe([
        "-    return [1];\n+    return [];",
        "-    return [1];\n+    return [];",
    ]);
});

it('leaves alone the code it is not written for', function (): void {
    $code = <<<'PHP'
        <?php

        function items(): array
        {
            return [];
        }

        function amount(): int
        {
            return 1;
        }

        function any()
        {
            return [1];
        }
        PHP;

    expect(Mutates::with(new AlwaysReturnEmptyArray(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new AlwaysReturnEmptyArray()->mutate(new Nop()))->toEqual(Unchanged::node());
});
