<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateLaravel\Mutators\RemoveDispatch;
use PhpParser\Node\Stmt\Nop;

it('is named in the laravel set, a removed call, untagged, with its own hint', function (): void {
    $mutator = new RemoveDispatch();

    expect($mutator->name()->value())->toBe('laravel/RemoveDispatch')
        ->and($mutator->family())->toBe(MutatorFamily::RemovedCall)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(Hint::that('No test fakes the event, job, mail or notification and asserts that it was sent.'));
});

it('removes each dispatch under both runners, emptying it under Infection', function (): void {
    $code = <<<'PHP'
        <?php

        use Illuminate\Support\Facades\Bus;
        use Illuminate\Support\Facades\Event;
        use Illuminate\Support\Facades\Mail;
        use Illuminate\Support\Facades\Notification;

        final class Checkout
        {
            public function pay($order, $admin)
            {
                event(new OrderPaid($order));
                dispatch(new SendReceipt($order));
                Event::dispatch(new OrderPaid($order));
                Bus::dispatch(new SendReceipt($order));
                Notification::send($order->user, new Paid());
                Mail::to($order->user)->cc($admin)->send(new Receipt());
                Mail::send(new Receipt());
            }
        }
        PHP;
    $mutates = Mutates::with(new RemoveDispatch(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe([
        "-        event(new OrderPaid(\$order));",
        "-        dispatch(new SendReceipt(\$order));",
        "-        Event::dispatch(new OrderPaid(\$order));",
        "-        Bus::dispatch(new SendReceipt(\$order));",
        "-        Notification::send(\$order->user, new Paid());",
        "-        Mail::to(\$order->user)->cc(\$admin)->send(new Receipt());",
        "-        Mail::send(new Receipt());",
    ])
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe([
            "-        event(new OrderPaid(\$order));\n+        ",
            "-        dispatch(new SendReceipt(\$order));\n+        ",
            "-        Event::dispatch(new OrderPaid(\$order));\n+        ",
            "-        Bus::dispatch(new SendReceipt(\$order));\n+        ",
            "-        Notification::send(\$order->user, new Paid());\n+        ",
            "-        Mail::to(\$order->user)->cc(\$admin)->send(new Receipt());\n+        ",
            "-        Mail::send(new Receipt());\n+        ",
        ]);
});

it('leaves alone a dispatch whose result is used, a listener, a mailer object, a queued mail and another send', function (): void {
    $code = <<<'PHP'
        <?php

        $sent = event(new OrderPaid($order));
        Event::listen(OrderPaid::class, $listener);
        $mailer->to($user)->send(new Receipt());
        Mail::to($user)->queue(new Receipt());
        $this->send();
        PHP;

    expect(Mutates::with(new RemoveDispatch(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new RemoveDispatch()->mutate(new Nop()))->toEqual(Unchanged::node());
});
