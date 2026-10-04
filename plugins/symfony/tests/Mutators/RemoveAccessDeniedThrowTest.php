<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateSymfony\Mutators\RemoveAccessDeniedThrow;
use PhpParser\Node\Stmt\Nop;

it('is named in the symfony set, an exception change, about security, with its own hint', function (): void {
    $mutator = new RemoveAccessDeniedThrow();

    expect($mutator->name()->value())->toBe('symfony/RemoveAccessDeniedThrow')
        ->and($mutator->family())->toBe(MutatorFamily::Exception)
        ->and($mutator->tags())->toEqual(Tags::of(Tag::security()))
        ->and($mutator->hint())->toEqual(Hint::that('No test checks that access is denied here.'));
});

it('removes the denial under both runners, emptying it under Infection', function (): void {
    $code = <<<'PHP'
        <?php

        final class PostController extends AbstractController
        {
            public function edit($post, $user)
            {
                if ($post->author !== $user) {
                    throw $this->createAccessDeniedException('Not yours.');
                }
            }
        }
        PHP;
    $mutates = Mutates::with(new RemoveAccessDeniedThrow(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe([
        "-            throw \$this->createAccessDeniedException('Not yours.');",
    ])
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe([
            "-            throw \$this->createAccessDeniedException('Not yours.');\n+            ",
        ]);
});

it('leaves alone a denial another object makes, another exception, and one that is not thrown', function (): void {
    $code = <<<'PHP'
        <?php

        throw $factory->createAccessDeniedException();
        throw $this->createNotFoundException();
        $denied = $this->createAccessDeniedException();
        PHP;

    expect(Mutates::with(new RemoveAccessDeniedThrow(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new RemoveAccessDeniedThrow()->mutate(new Nop()))->toEqual(Unchanged::node());
});
