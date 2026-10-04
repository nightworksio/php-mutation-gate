<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateSecurity\Mutators;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Removal;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateSecurity\Calls;
use NightWorksIO\MutationGateSecurity\SecuritySet;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Stmt\Expression;

/** A statement that only calls `session_regenerate_id(…)` is removed. */
final readonly class RemoveSessionRegenerate implements Mutator
{
    public function name(): MutatorName
    {
        return SecuritySet::mutator('RemoveSessionRegenerate');
    }

    public function family(): MutatorFamily
    {
        return MutatorFamily::RemovedCall;
    }

    public function tags(): Tags
    {
        return Tags::of(Tag::security());
    }

    public function handles(): NodeClasses
    {
        return NodeClasses::of(Expression::class);
    }

    public function mutate(Node $node): Removal|Unchanged
    {
        return $node instanceof Expression
            && Calls::ofFunction($node->expr, 'session_regenerate_id') instanceof FuncCall
            ? Removal::statement()
            : Unchanged::node();
    }

    public function hint(): Hint
    {
        return Hint::that('No test checks that the session id changes when the user signs in.');
    }
}
