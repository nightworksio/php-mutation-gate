<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateSymfony\Mutators;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Literals;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateSymfony\Calls;
use NightWorksIO\MutationGateSymfony\SymfonySet;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;

/** `->isGranted(…)` becomes `true`, on any receiver. */
final readonly class IsGrantedToTrue implements Mutator
{
    public function name(): MutatorName
    {
        return SymfonySet::mutator('IsGrantedToTrue');
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
        return NodeClasses::of(MethodCall::class);
    }

    public function mutate(Node $node): Node|Unchanged
    {
        return Calls::ofMethod($node, 'isGranted') ? Literals::true() : Unchanged::node();
    }

    public function hint(): Hint
    {
        return Hint::that('No test checks what happens when this grant is denied.');
    }
}
