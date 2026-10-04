<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateLaravel\Mutators\RemoveMiddleware;
use PhpParser\Node\Stmt\Nop;

it('is named in the laravel set, a removed call, about security, with its own hint', function (): void {
    $mutator = new RemoveMiddleware();

    expect($mutator->name()->value())->toBe('laravel/RemoveMiddleware')
        ->and($mutator->family())->toBe(MutatorFamily::RemovedCall)
        ->and($mutator->tags())->toEqual(Tags::of(Tag::security()))
        ->and($mutator->hint())->toEqual(Hint::that('No test sends a request this middleware refuses.'));
});

it('removes the middleware a constructor adds under both runners, emptying it under Infection', function (): void {
    $code = <<<'PHP'
        <?php

        final class AccountController extends Controller
        {
            public function __construct()
            {
                $this->middleware('auth');
            }
        }
        PHP;
    $mutates = Mutates::with(new RemoveMiddleware(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe([
        "-        \$this->middleware('auth');",
    ])
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe([
            "-        \$this->middleware('auth');\n+        ",
        ]);
});

it('leaves alone another call in a constructor, and middleware added outside one', function (): void {
    $code = <<<'PHP'
        <?php

        final class AccountController extends Controller
        {
            public function __construct()
            {
                $this->other('auth');
            }

            public function show()
            {
                $this->middleware('auth');
            }
        }

        $this->middleware('auth');
        PHP;

    expect(Mutates::with(new RemoveMiddleware(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new RemoveMiddleware()->mutate(new Nop()))->toEqual(Unchanged::node());
});
