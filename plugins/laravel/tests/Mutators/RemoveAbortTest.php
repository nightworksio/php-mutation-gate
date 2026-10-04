<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateLaravel\Mutators\RemoveAbort;
use PhpParser\Node\Stmt\Nop;

it('is named in the laravel set, a removed call, untagged, with its own hint', function (): void {
    $mutator = new RemoveAbort();

    expect($mutator->name()->value())->toBe('laravel/RemoveAbort')
        ->and($mutator->family())->toBe(MutatorFamily::RemovedCall)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(Hint::that('No test checks that the request is stopped when this condition holds.'));
});

it('removes a stop that refuses with no 401 or 403', function (): void {
    $code = <<<'PHP'
        <?php

        final class OrderController
        {
            public function pay($order, $status)
            {
                abort_if($order->isPaid(), 409);
                abort_unless($order->exists, $status);
                abort_if($order->user() !== $user, 403);
            }
        }
        PHP;
    $mutates = Mutates::with(new RemoveAbort(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe([
        "-        abort_if(\$order->isPaid(), 409);",
        "-        abort_unless(\$order->exists, \$status);",
    ])
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe([
            "-        abort_if(\$order->isPaid(), 409);\n+        ",
            "-        abort_unless(\$order->exists, \$status);\n+        ",
        ]);
});

it('leaves alone a stop that refuses, another function, and a stop whose result is used', function (): void {
    $code = <<<'PHP'
        <?php

        abort(409);
        $stopped = abort_if($paid, 409);
        Acme\abort_if($paid, 409);
        PHP;

    expect(Mutates::with(new RemoveAbort(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new RemoveAbort()->mutate(new Nop()))->toEqual(Unchanged::node());
});
