<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateLaravel\Mutators;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Removal;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateLaravel\Calls;
use NightWorksIO\MutationGateLaravel\Facade;
use NightWorksIO\MutationGateLaravel\LaravelSet;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Expression;

/**
 * A statement that dispatches is removed: `event(…)`, `dispatch(…)`,
 * `Event::dispatch(…)`, `Bus::dispatch(…)`, `Notification::send(…)`,
 * `Mail::send(…)`, and a `->send(…)` on a chain that starts at the `Mail` facade.
 */
final readonly class RemoveDispatch implements Mutator
{
    private const string DISPATCH = 'dispatch';

    private const string SEND = 'send';

    public function name(): MutatorName
    {
        return LaravelSet::mutator('RemoveDispatch');
    }

    public function family(): MutatorFamily
    {
        return MutatorFamily::RemovedCall;
    }

    public function tags(): Tags
    {
        return Tags::none();
    }

    public function handles(): NodeClasses
    {
        return NodeClasses::of(Expression::class);
    }

    public function mutate(Node $node): Removal|Unchanged
    {
        return $node instanceof Expression && $this->dispatches($node->expr) ? Removal::statement() : Unchanged::node();
    }

    public function hint(): Hint
    {
        return Hint::that('No test fakes the event, job, mail or notification and asserts that it was sent.');
    }

    private function dispatches(Expr $call): bool
    {
        return Calls::ofFunction($call, 'event', self::DISPATCH)
            || Calls::onFacade($call, Facade::Event, self::DISPATCH)
            || Calls::onFacade($call, Facade::Bus, self::DISPATCH)
            || Calls::onFacade($call, Facade::Notification, self::SEND)
            || Calls::onFacade($call, Facade::Mail, self::SEND)
            || ($call instanceof MethodCall && Calls::named($call->name, self::SEND) && $this->fromMail($call->var));
    }

    /** Whether a chain of calls starts at a static call on the `Mail` facade. */
    private function fromMail(Expr $chain): bool
    {
        $at = $chain;

        while ($at instanceof MethodCall) {
            $at = $at->var;
        }

        return $at instanceof StaticCall && $at->class instanceof Name && Calls::isFacade($at->class, Facade::Mail);
    }
}
