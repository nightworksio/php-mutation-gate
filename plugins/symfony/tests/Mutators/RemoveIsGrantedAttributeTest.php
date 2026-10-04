<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateSymfony\Mutators\RemoveIsGrantedAttribute;
use PhpParser\Node\Stmt\Nop;

it('is named in the symfony set, a removed call, about security, with its own hint', function (): void {
    $mutator = new RemoveIsGrantedAttribute();

    expect($mutator->name()->value())->toBe('symfony/RemoveIsGrantedAttribute')
        ->and($mutator->family())->toBe(MutatorFamily::RemovedCall)
        ->and($mutator->tags())->toEqual(Tags::of(Tag::security()))
        ->and($mutator->hint())->toEqual(Hint::that('No test calls this without the grant and checks that it is refused.'));
});

it('removes the attribute on a class under Pest, and on a method under both runners', function (): void {
    $code = <<<'PHP'
        <?php

        use Symfony\Component\Routing\Attribute\Route;
        use Symfony\Component\Security\Http\Attribute\IsGranted;

        #[IsGranted('ROLE_ADMIN')]
        final class AdminController
        {
        }

        final class PostController
        {
            #[Route('/edit'), IsGranted('EDIT')]
            public function edit()
            {
            }

            #[\Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted('VIEW')]
            public function view()
            {
            }
        }
        PHP;
    $mutates = Mutates::with(new RemoveIsGrantedAttribute(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe([
        "-#[IsGranted('ROLE_ADMIN')]",
        "-    #[Route('/edit'), IsGranted('EDIT')]\n+    #[Route('/edit')]",
        "-    #[\\Sensio\\Bundle\\FrameworkExtraBundle\\Configuration\\IsGranted('VIEW')]",
    ])
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe([
            "-    #[Route('/edit'), IsGranted('EDIT')]\n+    #[Route('/edit')]",
            "-    #[\\Sensio\\Bundle\\FrameworkExtraBundle\\Configuration\\IsGranted('VIEW')]",
        ]);
});

it('leaves alone an attribute of another namespace and another attribute', function (): void {
    $code = <<<'PHP'
        <?php

        use Acme\IsGranted;
        use Symfony\Component\Routing\Attribute\Route;

        #[IsGranted('ROLE_ADMIN')]
        final class PostController
        {
            #[Route('/edit')]
            public function edit()
            {
            }
        }
        PHP;

    expect(Mutates::with(new RemoveIsGrantedAttribute(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new RemoveIsGrantedAttribute()->mutate(new Nop()))->toEqual(Unchanged::node());
});
