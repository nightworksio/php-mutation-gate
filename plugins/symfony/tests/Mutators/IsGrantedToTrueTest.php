<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateSymfony\Mutators\IsGrantedToTrue;
use PhpParser\Node\Stmt\Nop;

it('is named in the symfony set, a condition, about security, with its own hint', function (): void {
    $mutator = new IsGrantedToTrue();

    expect($mutator->name()->value())->toBe('symfony/IsGrantedToTrue')
        ->and($mutator->family())->toBe(MutatorFamily::Condition)
        ->and($mutator->tags())->toEqual(Tags::of(Tag::security()))
        ->and($mutator->hint())->toEqual(Hint::that('No test checks what happens when this grant is denied.'));
});

it('grants every check, on any receiver, under both runners', function (): void {
    $code = <<<'PHP'
        <?php

        final class PostController extends AbstractController
        {
            public function edit($post)
            {
                if ($this->isGranted('ROLE_ADMIN')) {
                    return 1;
                }

                return $this->security->isGranted('EDIT', $post);
            }
        }
        PHP;
    $changed = [
        "-        if (\$this->isGranted('ROLE_ADMIN')) {\n+        if (true) {",
        "-        return \$this->security->isGranted('EDIT', \$post);\n+        return true;",
    ];
    $mutates = Mutates::with(new IsGrantedToTrue(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe($changed)
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe($changed);
});

it('leaves alone a static call, a method named by a variable and another method', function (): void {
    $code = <<<'PHP'
        <?php

        Security::isGranted('ROLE_ADMIN');
        $this->{$check}('ROLE_ADMIN');
        $this->getUser();
        PHP;

    expect(Mutates::with(new IsGrantedToTrue(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new IsGrantedToTrue()->mutate(new Nop()))->toEqual(Unchanged::node());
});
