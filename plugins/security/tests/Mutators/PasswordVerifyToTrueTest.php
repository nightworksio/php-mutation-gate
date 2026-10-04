<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateSecurity\Mutators\PasswordVerifyToTrue;
use PhpParser\Node\Stmt\Nop;

it('is named in the security set, a condition, about security, with its own hint', function (): void {
    $mutator = new PasswordVerifyToTrue();

    expect($mutator->name()->value())->toBe('security/PasswordVerifyToTrue')
        ->and($mutator->family())->toBe(MutatorFamily::Condition)
        ->and($mutator->tags())->toEqual(Tags::of(Tag::security()))
        ->and($mutator->hint())->toEqual(Hint::that('No test checks that `password_verify()` refuses a wrong password.'));
});

it('accepts every password under both runners', function (): void {
    $code = <<<'PHP'
        <?php

        final class Login
        {
            public function accepts($user, $password)
            {
                return password_verify($password, $user->hash);
            }
        }
        PHP;
    $changed = [
        "-        return password_verify(\$password, \$user->hash);\n+        return true;",
    ];
    $mutates = Mutates::with(new PasswordVerifyToTrue(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe($changed)
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe($changed);
});

it('leaves alone the other password functions', function (): void {
    $code = <<<'PHP'
        <?php

        password_hash($password, PASSWORD_DEFAULT);
        password_needs_rehash($hash, PASSWORD_DEFAULT);
        PHP;

    expect(Mutates::with(new PasswordVerifyToTrue(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new PasswordVerifyToTrue()->mutate(new Nop()))->toEqual(Unchanged::node());
});
