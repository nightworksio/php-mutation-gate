<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateSymfony\Mutators\RemoveMessageDispatch;
use PhpParser\Node\Stmt\Nop;

it('is named in the symfony set, a removed call, untagged, with its own hint', function (): void {
    $mutator = new RemoveMessageDispatch();

    expect($mutator->name()->value())->toBe('symfony/RemoveMessageDispatch')
        ->and($mutator->family())->toBe(MutatorFamily::RemovedCall)
        ->and($mutator->tags())->toEqual(Tags::none())
        ->and($mutator->hint())->toEqual(Hint::that('No test checks that this message is dispatched.'));
});

it('removes a dispatch on a bus a property, a promoted parameter or a parameter declares, under both runners', function (): void {
    $code = <<<'PHP'
        <?php

        use Symfony\Component\Messenger\MessageBusInterface;

        final class Checkout
        {
            private MessageBusInterface $bus;

            public function __construct(private readonly ?MessageBusInterface $queue)
            {
            }

            public function pay(MessageBusInterface $events)
            {
                $this->bus->dispatch(new OrderPaid());
                $events->dispatch(new OrderPaid());
                $this->queue->dispatch(new SendReceipt());
            }
        }
        PHP;
    $mutates = Mutates::with(new RemoveMessageDispatch(), $code);

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe([
        "-        \$this->bus->dispatch(new OrderPaid());",
        "-        \$events->dispatch(new OrderPaid());",
        "-        \$this->queue->dispatch(new SendReceipt());",
    ])
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe([
            "-        \$this->bus->dispatch(new OrderPaid());\n+        ",
            "-        \$events->dispatch(new OrderPaid());\n+        ",
            "-        \$this->queue->dispatch(new SendReceipt());\n+        ",
        ]);
});

it('leaves alone a bus with no type, an event dispatcher, another method, a dispatch whose result is used, and one outside any function', function (): void {
    $code = <<<'PHP'
        <?php

        use Symfony\Component\Messenger\MessageBusInterface;

        final class Checkout
        {
            private $bus;

            private EventDispatcherInterface $dispatcher;

            public function __construct(private readonly MessageBusInterface $queue)
            {
            }

            public function pay($events, MessageBusInterface $other)
            {
                $this->bus->dispatch(new OrderPaid());
                $this->dispatcher->dispatch(new OrderPaid());
                $events->dispatch(new OrderPaid());
                $other->send(new OrderPaid());
                $sent = $this->queue->dispatch(new OrderPaid());
            }
        }

        $bus->dispatch(new OrderPaid());
        PHP;

    expect(Mutates::with(new RemoveMessageDispatch(), $code)->underPest())->toHaveCount(0);
});

it('leaves alone a node it does not handle', function (): void {
    expect(new RemoveMessageDispatch()->mutate(new Nop()))->toEqual(Unchanged::node());
});
