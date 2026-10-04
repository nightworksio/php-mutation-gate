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
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Stmt\Expression;

/** `throw $this->createAccessDeniedException(…)` is removed. */
final readonly class RemoveAccessDeniedThrow implements Mutator
{
    public function name(): MutatorName
    {
        return SymfonySet::mutator('RemoveAccessDeniedThrow');
    }

    public function family(): MutatorFamily
    {
        return MutatorFamily::Exception;
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
            && $node->expr instanceof Throw_
            && Calls::onThis($node->expr->expr, 'createAccessDeniedException')
            ? Removal::statement()
            : Unchanged::node();
    }

    public function hint(): Hint
    {
        return Hint::that('No test checks that access is denied here.');
    }
}
