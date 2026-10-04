<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateLaravel\Mutators\GateAllowsToTrue;
use PhpParser\Node\Stmt\Nop;

it('is named in the laravel set, a condition about security, with its own hint', function (): void {
    $mutator = new GateAllowsToTrue();

    expect($mutator->name()->value())->toBe('laravel/GateAllowsToTrue')
        ->and($mutator->family())->toBe(MutatorFamily::Condition)
        ->and($mutator->tags())->toEqual(Tags::of(Tag::security()))
        ->and($mutator->hint())->toEqual(Hint::that('No test checks that the action is refused when the gate says no.'));
});

it('answers yes for the gate under both runners, by the facade or its alias', function (): void {
    $code = <<<'PHP'
        <?php

        namespace App\Http\Controllers;

        use Illuminate\Support\Facades\Gate;

        final class PostController
        {
            public function update($post)
            {
                if (Gate::allows('update', $post)) {
                    return 1;
                }

                if (\Gate::check('edit', $post)) {
                    return 2;
                }

                return GATE::denies('delete', $post);
            }
        }
        PHP;
    $changed = [
        "-        if (Gate::allows('update', \$post)) {\n+        if (true) {",
        "-        if (\\Gate::check('edit', \$post)) {\n+        if (true) {",
        "-        return GATE::denies('delete', \$post);\n+        return false;",
    ];
    $mutates = Mutates::with(new GateAllowsToTrue(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe($changed)
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe($changed);
});

it('leaves alone a gate of another class, another method and a call on an object', function (): void {
    $code = <<<'PHP'
        <?php

        use Acme\Gate;

        Gate::allows('update');
        Illuminate\Support\Facades\Gate::inspect('update');
        $gate->allows('update');
        PHP;

    expect(Mutates::with(new GateAllowsToTrue(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new GateAllowsToTrue()->mutate(new Nop()))->toEqual(Unchanged::node());
});
