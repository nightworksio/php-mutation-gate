<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateLaravel\Mutators\RemoveAuthorize;
use PhpParser\Node\Stmt\Nop;

it('is named in the laravel set, a removed call, about security, with its own hint', function (): void {
    $mutator = new RemoveAuthorize();

    expect($mutator->name()->value())->toBe('laravel/RemoveAuthorize')
        ->and($mutator->family())->toBe(MutatorFamily::RemovedCall)
        ->and($mutator->tags())->toEqual(Tags::of(Tag::security()))
        ->and($mutator->hint())->toEqual(Hint::that('No test checks that the action is refused when the policy says no.'));
});

it('removes the authorization of a controller and of the gate under both runners, emptying it under Infection', function (): void {
    $code = <<<'PHP'
        <?php

        namespace App\Http\Controllers;

        use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
        use Illuminate\Support\Facades\Gate;

        final class PostController extends Controller
        {
            public function update($post)
            {
                $this->authorize('update', $post);
                Gate::authorize('update', $post);
            }
        }

        final class Reports
        {
            use AuthorizesRequests;

            public function show($post)
            {
                $this->authorize('view', $post);
            }
        }
        PHP;
    $mutates = Mutates::with(new RemoveAuthorize(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe([
        "-        \$this->authorize('update', \$post);",
        "-        Gate::authorize('update', \$post);",
        "-        \$this->authorize('view', \$post);",
    ])
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe([
            "-        \$this->authorize('update', \$post);\n+        ",
            "-        Gate::authorize('update', \$post);\n+        ",
            "-        \$this->authorize('view', \$post);\n+        ",
        ]);
});

it('leaves alone an authorization in a class that neither extends a controller nor authorizes requests, and one whose result is used', function (): void {
    $code = <<<'PHP'
        <?php

        namespace App\Policies;

        final class PostPolicy
        {
            public function update($post)
            {
                $this->authorize('update', $post);
                $other->authorize('update', $post);
                $allowed = $this->authorize('update', $post);
            }
        }

        $this->authorize('update', $post);
        PHP;

    expect(Mutates::with(new RemoveAuthorize(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new RemoveAuthorize()->mutate(new Nop()))->toEqual(Unchanged::node());
});
