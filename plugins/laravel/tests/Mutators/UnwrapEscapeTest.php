<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateLaravel\Mutators\UnwrapEscape;
use PhpParser\Node\Stmt\Nop;

it('is named in the laravel set, an unwrap, about security, with its own hint', function (): void {
    $mutator = new UnwrapEscape();

    expect($mutator->name()->value())->toBe('laravel/UnwrapEscape')
        ->and($mutator->family())->toBe(MutatorFamily::Unwrap)
        ->and($mutator->tags())->toEqual(Tags::of(Tag::security()))
        ->and($mutator->hint())->toEqual(Hint::that('No test passes markup through `e()` and checks that it comes out escaped.'));
});

it('leaves the value unescaped under both runners', function (): void {
    $code = <<<'PHP'
        <?php

        final class Badge
        {
            public function html($name)
            {
                return '<b>' . e($name) . '</b>';
            }
        }
        PHP;
    $changed = [
        "-        return '<b>' . e(\$name) . '</b>';\n+        return '<b>' . \$name . '</b>';",
    ];
    $mutates = Mutates::with(new UnwrapEscape(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe($changed)
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe($changed);
});

it('leaves alone a first-class callable, a function of another namespace and another function', function (): void {
    $code = <<<'PHP'
        <?php

        $escape = e(...);
        $escaped = Acme\e($name);
        $escaped = escape($name);
        PHP;

    expect(Mutates::with(new UnwrapEscape(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new UnwrapEscape()->mutate(new Nop()))->toEqual(Unchanged::node());
});
