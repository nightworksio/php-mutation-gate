<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateSymfony\Mutators\FormValidToTrue;
use PhpParser\Node\Stmt\Nop;

it('is named in the symfony set, a condition, untagged, with its own hint', function (): void {
    $mutator = new FormValidToTrue();

    expect($mutator->name()->value())->toBe('symfony/FormValidToTrue')
        ->and($mutator->family())->toBe(MutatorFamily::Condition)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(Hint::that('No test submits invalid data and checks that the form refuses it.'));
});

it('accepts every submission of a form under both runners', function (): void {
    $code = <<<'PHP'
        <?php

        final class SignUpController
        {
            public function new($form)
            {
                if ($form->isSubmitted() && $form->isValid()) {
                    return 1;
                }

                return $this->signUpForm->isValid();
            }
        }
        PHP;
    $changed = [
        "-        if (\$form->isSubmitted() && \$form->isValid()) {\n+        if (\$form->isSubmitted() && true) {",
        "-        return \$this->signUpForm->isValid();\n+        return true;",
    ];
    $mutates = Mutates::with(new FormValidToTrue(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe($changed)
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe($changed);
});

it('leaves alone a validator, a name that only ends in form, another method and a form found another way', function (): void {
    $code = <<<'PHP'
        <?php

        $validator->isValid();
        $platform->isValid();
        $form->isSubmitted();
        $this->forms[0]->isValid();
        PHP;

    expect(Mutates::with(new FormValidToTrue(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new FormValidToTrue()->mutate(new Nop()))->toEqual(Unchanged::node());
});
