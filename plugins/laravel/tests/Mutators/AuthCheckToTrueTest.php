<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateLaravel\Mutators\AuthCheckToTrue;
use PhpParser\Node\Stmt\Nop;

it('is named in the laravel set, a condition, about security, with its own hint', function (): void {
    $mutator = new AuthCheckToTrue();

    expect($mutator->name()->value())->toBe('laravel/AuthCheckToTrue')
        ->and($mutator->family())->toBe(MutatorFamily::Condition)
        ->and($mutator->tags())->toEqual(Tags::of(Tag::security()))
        ->and($mutator->hint())->toEqual(Hint::that('No test runs this as a guest and checks what a guest is refused.'));
});

it('signs a user in for every check under both runners', function (): void {
    $code = <<<'PHP'
        <?php

        use Illuminate\Support\Facades\Auth;

        final class Menu
        {
            public function items()
            {
                if (Auth::check()) {
                    return 1;
                }

                return \Auth::guest();
            }
        }
        PHP;
    $changed = [
        "-        if (Auth::check()) {\n+        if (true) {",
        "-        return \\Auth::guest();\n+        return false;",
    ];
    $mutates = Mutates::with(new AuthCheckToTrue(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe($changed)
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe($changed);
});

it('leaves alone another method of the guard, the helper, and a class of another namespace', function (): void {
    $code = <<<'PHP'
        <?php

        Auth::user();
        auth()->check();
        Acme\Auth::check();
        PHP;

    expect(Mutates::with(new AuthCheckToTrue(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new AuthCheckToTrue()->mutate(new Nop()))->toEqual(Unchanged::node());
});
