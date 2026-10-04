<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateLaravel\Mutators\HashCheckToTrue;
use PhpParser\Node\Stmt\Nop;

it('is named in the laravel set, a condition, about security, with its own hint', function (): void {
    $mutator = new HashCheckToTrue();

    expect($mutator->name()->value())->toBe('laravel/HashCheckToTrue')
        ->and($mutator->family())->toBe(MutatorFamily::Condition)
        ->and($mutator->tags())->toEqual(Tags::of(Tag::security()))
        ->and($mutator->hint())->toEqual(Hint::that('No test checks that a wrong password is refused.'));
});

it('accepts every password under both runners', function (): void {
    $code = <<<'PHP'
        <?php

        use Illuminate\Support\Facades\Hash;

        final class Login
        {
            public function accepts($user, $password)
            {
                return Hash::check($password, $user->password);
            }
        }
        PHP;
    $changed = [
        "-        return Hash::check(\$password, \$user->password);\n+        return true;",
    ];
    $mutates = Mutates::with(new HashCheckToTrue(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe($changed)
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe($changed);
});

it('leaves alone another method of the facade and a check on an object', function (): void {
    $code = <<<'PHP'
        <?php

        Hash::make($password);
        $hasher->check($password, $hash);
        PHP;

    expect(Mutates::with(new HashCheckToTrue(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new HashCheckToTrue()->mutate(new Nop()))->toEqual(Unchanged::node());
});
