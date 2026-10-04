<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateSymfony\Mutators\RemoveDenyAccess;
use PhpParser\Node\Stmt\Nop;

it('is named in the symfony set, a removed call, about security, with its own hint', function (): void {
    $mutator = new RemoveDenyAccess();

    expect($mutator->name()->value())->toBe('symfony/RemoveDenyAccess')
        ->and($mutator->family())->toBe(MutatorFamily::RemovedCall)
        ->and($mutator->tags())->toEqual(Tags::of(Tag::security()))
        ->and($mutator->hint())->toEqual(Hint::that('No test checks that the action is refused to a user without this grant.'));
});

it('removes the access check under both runners, emptying it under Infection', function (): void {
    $code = <<<'PHP'
        <?php

        final class AdminController extends AbstractController
        {
            public function index()
            {
                $this->denyAccessUnlessGranted('ROLE_ADMIN');
                return 1;
            }
        }
        PHP;
    $mutates = Mutates::with(new RemoveDenyAccess(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe([
        "-        \$this->denyAccessUnlessGranted('ROLE_ADMIN');",
    ])
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe([
            "-        \$this->denyAccessUnlessGranted('ROLE_ADMIN');\n+        ",
        ]);
});

it('leaves alone a check on another object and another method', function (): void {
    $code = <<<'PHP'
        <?php

        $checker->denyAccessUnlessGranted('ROLE_ADMIN');
        $this->isGranted('ROLE_ADMIN');
        PHP;

    expect(Mutates::with(new RemoveDenyAccess(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new RemoveDenyAccess()->mutate(new Nop()))->toEqual(Unchanged::node());
});
