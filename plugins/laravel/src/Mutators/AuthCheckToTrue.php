<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateLaravel\Mutators;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Literals;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateLaravel\Calls;
use NightWorksIO\MutationGateLaravel\Facade;
use NightWorksIO\MutationGateLaravel\LaravelSet;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;

/** `Auth::check()` becomes `true`, and `Auth::guest()` becomes `false`. */
final readonly class AuthCheckToTrue implements Mutator
{
    public function name(): MutatorName
    {
        return LaravelSet::mutator('AuthCheckToTrue');
    }

    public function family(): MutatorFamily
    {
        return MutatorFamily::Condition;
    }

    public function tags(): Tags
    {
        return Tags::of(Tag::security());
    }

    public function handles(): NodeClasses
    {
        return NodeClasses::of(StaticCall::class);
    }

    public function mutate(Node $node): Node|Unchanged
    {
        return match (true) {
            Calls::onFacade($node, Facade::Auth, 'check') => Literals::true(),
            Calls::onFacade($node, Facade::Auth, 'guest') => Literals::false(),
            default => Unchanged::node(),
        };
    }

    public function hint(): Hint
    {
        return Hint::that('No test runs this as a guest and checks what a guest is refused.');
    }
}
