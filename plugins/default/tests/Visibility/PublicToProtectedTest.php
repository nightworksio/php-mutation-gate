<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\Visibility\PublicToProtected;
use PhpParser\Node\Stmt\Nop;

it('is named in the default set, in its family, untagged, with the hint of its family', function (): void {
    $mutator = new PublicToProtected();

    expect($mutator->name()->value())->toBe('default/PublicToProtected')
        ->and($mutator->family())->toBe(MutatorFamily::Visibility)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(FamilyHint::ofItsFamily());
});

it('changes the code it is written for', function (): void {
    $code = <<<'PHP'
        <?php

        final class Money
        {
            public function amount()
            {
                return 1;
            }

            function total()
            {
                return 2;
            }
        }
        PHP;

    expect(iterator_to_array(Mutates::with(new PublicToProtected(), $code)->underPest(), preserve_keys: false))->toBe([
        "-    public function amount()\n+    protected function amount()",
        "-    function total()\n+    protected function total()",
    ]);
});

it('leaves alone the code it is not written for', function (): void {
    $code = <<<'PHP'
        <?php

        interface Priced
        {
            public function amount();
        }

        enum Currency
        {
            case Euro;

            public function sign()
            {
                return 1;
            }
        }

        final class Money
        {
            public function __construct()
            {
            }

            protected function amount()
            {
                return 1;
            }
        }
        PHP;

    expect(Mutates::with(new PublicToProtected(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new PublicToProtected()->mutate(new Nop()))->toEqual(Unchanged::node());
});
