<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateSymfony\Mutators;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Removal;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateSymfony\Calls;
use NightWorksIO\MutationGateSymfony\SymfonySet;
use PhpParser\Node;
use PhpParser\Node\Stmt\Expression;

/** `$this->denyAccessUnlessGranted(…)` is removed, as an `AbstractController` calls it. */
final readonly class RemoveDenyAccess implements Mutator
{
    public function name(): MutatorName
    {
        return SymfonySet::mutator('RemoveDenyAccess');
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
        return $node instanceof Expression && Calls::onThis($node->expr, 'denyAccessUnlessGranted')
            ? Removal::statement()
            : Unchanged::node();
    }

    public function hint(): Hint
    {
        return Hint::that('No test checks that the action is refused to a user without this grant.');
    }
}
