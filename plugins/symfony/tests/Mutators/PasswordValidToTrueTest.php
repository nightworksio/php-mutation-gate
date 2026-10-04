<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateSymfony\Mutators\PasswordValidToTrue;
use PhpParser\Node\Stmt\Nop;

it('is named in the symfony set, a condition, about security, with its own hint', function (): void {
    $mutator = new PasswordValidToTrue();

    expect($mutator->name()->value())->toBe('symfony/PasswordValidToTrue')
        ->and($mutator->family())->toBe(MutatorFamily::Condition)
        ->and($mutator->tags())->toEqual(Tags::of(Tag::security()))
        ->and($mutator->hint())->toEqual(Hint::that('No test checks that `isPasswordValid()` refuses a wrong password.'));
});

it('accepts every password, on any receiver, under both runners', function (): void {
    $code = <<<'PHP'
        <?php

        final class Login
        {
            public function accepts($user, $password)
            {
                return $this->hasher->isPasswordValid($user, $password);
            }
        }
        PHP;
    $changed = [
        "-        return \$this->hasher->isPasswordValid(\$user, \$password);\n+        return true;",
    ];
    $mutates = Mutates::with(new PasswordValidToTrue(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe($changed)
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe($changed);
});

it('leaves alone another method and a static call', function (): void {
    $code = <<<'PHP'
        <?php

        $hasher->hashPassword($user, $password);
        Hasher::isPasswordValid($user, $password);
        PHP;

    expect(Mutates::with(new PasswordValidToTrue(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new PasswordValidToTrue()->mutate(new Nop()))->toEqual(Unchanged::node());
});
