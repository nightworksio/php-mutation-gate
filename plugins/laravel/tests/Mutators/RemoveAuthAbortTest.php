<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateLaravel\Mutators\RemoveAuthAbort;
use PhpParser\Node\Stmt\Nop;

it('is named in the laravel set, a removed call, about security, with its own hint', function (): void {
    $mutator = new RemoveAuthAbort();

    expect($mutator->name()->value())->toBe('laravel/RemoveAuthAbort')
        ->and($mutator->family())->toBe(MutatorFamily::RemovedCall)
        ->and($mutator->tags())->toEqual(Tags::of(Tag::security()))
        ->and($mutator->hint())->toEqual(Hint::that('No test checks that the request is refused when this condition holds.'));
});

it('removes a stop that refuses with 401 or 403', function (): void {
    $code = <<<'PHP'
        <?php

        final class OrderController
        {
            public function pay($order, $user)
            {
                abort_if($order->user() !== $user, 403);
                \abort_unless($user, 401);
                abort_if($order->isPaid(), 409);
            }
        }
        PHP;
    $mutates = Mutates::with(new RemoveAuthAbort(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe([
        "-        abort_if(\$order->user() !== \$user, 403);",
        "-        \\abort_unless(\$user, 401);",
    ])
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe([
            "-        abort_if(\$order->user() !== \$user, 403);\n+        ",
            "-        \\abort_unless(\$user, 401);\n+        ",
        ]);
});

it('leaves alone a stop with another code, one held in a variable, and one written as a string', function (): void {
    $code = <<<'PHP'
        <?php

        abort(403);
        abort_if($denied, $status);
        abort_if($denied, '403');
        PHP;

    expect(Mutates::with(new RemoveAuthAbort(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new RemoveAuthAbort()->mutate(new Nop()))->toEqual(Unchanged::node());
});
