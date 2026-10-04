<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateSymfony\Mutators\CsrfValidToTrue;
use PhpParser\Node\Stmt\Nop;

it('is named in the symfony set, a condition, about security, with its own hint', function (): void {
    $mutator = new CsrfValidToTrue();

    expect($mutator->name()->value())->toBe('symfony/CsrfValidToTrue')
        ->and($mutator->family())->toBe(MutatorFamily::Condition)
        ->and($mutator->tags())->toEqual(Tags::of(Tag::security()))
        ->and($mutator->hint())->toEqual(Hint::that('No test submits a wrong CSRF token and checks that it is refused.'));
});

it('accepts every token under both runners', function (): void {
    $code = <<<'PHP'
        <?php

        final class PostController extends AbstractController
        {
            public function delete($token)
            {
                if ($this->isCsrfTokenValid('delete', $token)) {
                    return 1;
                }
            }
        }
        PHP;
    $changed = [
        "-        if (\$this->isCsrfTokenValid('delete', \$token)) {\n+        if (true) {",
    ];
    $mutates = Mutates::with(new CsrfValidToTrue(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe($changed)
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe($changed);
});

it('leaves alone a check on another object and another method', function (): void {
    $code = <<<'PHP'
        <?php

        $manager->isCsrfTokenValid('delete', $token);
        $this->isTokenValid($token);
        PHP;

    expect(Mutates::with(new CsrfValidToTrue(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new CsrfValidToTrue()->mutate(new Nop()))->toEqual(Unchanged::node());
});
