<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\String\EmptyStringToNotEmpty;
use PhpParser\Node\Stmt\Nop;

it('is named in the default set, in its family, untagged, with the hint of its family', function (): void {
    $mutator = new EmptyStringToNotEmpty();

    expect($mutator->name()->value())->toBe('default/EmptyStringToNotEmpty')
        ->and($mutator->family())->toBe(MutatorFamily::Literal)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(FamilyHint::ofItsFamily());
});

it('changes the code it is written for, as Pest does but for the text it writes', function (): void {
    $code = <<<'PHP'
        <?php

        return '';
        PHP;

    expect(iterator_to_array(Mutates::with(new EmptyStringToNotEmpty(), $code)->underPest(), preserve_keys: false))->toBe([
        "-return '';\n+return 'mutation-gate was here';",
    ]);
});

it('leaves alone the code it is not written for', function (): void {
    $code = <<<'PHP'
        <?php

        return 'a';
        PHP;

    expect(Mutates::with(new EmptyStringToNotEmpty(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new EmptyStringToNotEmpty()->mutate(new Nop()))->toEqual(Unchanged::node());
});
