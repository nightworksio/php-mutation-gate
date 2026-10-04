<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateLaravel\Mutators\FirstOrFailToFirst;
use PhpParser\Node\Stmt\Nop;

it('is named in the laravel set, an exception change, untagged, with its own hint', function (): void {
    $mutator = new FirstOrFailToFirst();

    expect($mutator->name()->value())->toBe('laravel/FirstOrFailToFirst')
        ->and($mutator->family())->toBe(MutatorFamily::Exception)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(Hint::that('No test asks for a record that does not exist and expects it not to be found.'));
});

it('finds nothing where the record is missing, on a model class and on a query, under both runners', function (): void {
    $code = <<<'PHP'
        <?php

        final class PostController
        {
            public function show($id)
            {
                $post = Post::findOrFail($id);

                return $post->comments()->where('approved', true)->firstOrFail();
            }
        }
        PHP;
    $changed = [
        "-        \$post = Post::findOrFail(\$id);\n+        \$post = Post::find(\$id);",
        "-        return \$post->comments()->where('approved', true)->firstOrFail();\n+        return \$post->comments()->where('approved', true)->first();",
    ];
    $mutates = Mutates::with(new FirstOrFailToFirst(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe($changed)
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe($changed);
});

it('leaves alone the forgiving methods, a method named by a variable and another method', function (): void {
    $code = <<<'PHP'
        <?php

        Post::first();
        $query->find($id);
        $query->{$method}();
        $query->failOrFirst();
        PHP;

    expect(Mutates::with(new FirstOrFailToFirst(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new FirstOrFailToFirst()->mutate(new Nop()))->toEqual(Unchanged::node());
});
