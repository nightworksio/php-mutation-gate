<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\ControlStructures\ForAlwaysFalse;
use PhpParser\Node\Stmt\Nop;

it('is named in the default set, in its family, untagged, with the hint of its family', function (): void {
    $mutator = new ForAlwaysFalse();

    expect($mutator->name()->value())->toBe('default/ForAlwaysFalse')
        ->and($mutator->family())->toBe(MutatorFamily::Collection)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(FamilyHint::ofItsFamily());
});

it('changes the code it is written for, as Pest does', function (): void {
    $code = <<<'PHP'
        <?php

        for ($i = 0; $i < 3; $i++) {
            echo $i;
        }
        PHP;

    expect(iterator_to_array(Mutates::with(new ForAlwaysFalse(), $code)->underPest(), preserve_keys: false))->toBe([
        "-for (\$i = 0; \$i < 3; \$i++) {\n+for (\$i = 0; false; \$i++) {",
    ]);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new ForAlwaysFalse()->mutate(new Nop()))->toEqual(Unchanged::node());
});
