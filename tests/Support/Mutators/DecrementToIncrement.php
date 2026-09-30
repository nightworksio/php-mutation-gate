<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support\Mutators;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use PhpParser\Node;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;

/** Turns `$a--` into `$a++`, which never ends a loop that counts down to nothing. */
final readonly class DecrementToIncrement implements Mutator
{
    public function name(): MutatorName
    {
        return MutatorName::of('acme', 'DecrementToIncrement');
    }

    public function family(): MutatorFamily
    {
        return MutatorFamily::Arithmetic;
    }

    public function tags(): Tags
    {
        return Tags::none();
    }

    public function handles(): NodeClasses
    {
        return NodeClasses::of(PostDec::class);
    }

    public function mutate(Node $node): Node|Unchanged
    {
        return $node instanceof PostDec ? new PostInc($node->var, $node->getAttributes()) : Unchanged::node();
    }

    public function hint(): FamilyHint
    {
        return FamilyHint::ofItsFamily();
    }
}
