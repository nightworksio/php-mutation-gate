<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateDefault\Removal;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\DefaultSet;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;

/** `?->` becomes `->`. */
final readonly class RemoveNullSafeOperator implements Mutator
{
    public function name(): MutatorName
    {
        return DefaultSet::mutator('RemoveNullSafeOperator');
    }

    public function family(): MutatorFamily
    {
        return MutatorFamily::Condition;
    }

    public function tags(): Tags
    {
        return Tags::none();
    }

    public function handles(): NodeClasses
    {
        return NodeClasses::of(NullsafeMethodCall::class, NullsafePropertyFetch::class);
    }

    public function mutate(Node $node): Node|Unchanged
    {
        return match (true) {
            $node instanceof NullsafePropertyFetch
                => new PropertyFetch($node->var, $node->name, $node->getAttributes()),
            $node instanceof NullsafeMethodCall
                => new MethodCall($node->var, $node->name, $node->getRawArgs(), $node->getAttributes()),
            default => Unchanged::node(),
        };
    }

    public function hint(): FamilyHint
    {
        return FamilyHint::ofItsFamily();
    }
}
