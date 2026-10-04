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

/** `$this->isCsrfTokenValid(…)` becomes `true`. */
final readonly class CsrfValidToTrue implements Mutator
{
    public function name(): MutatorName
    {
        return SymfonySet::mutator('CsrfValidToTrue');
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
        return Calls::onThis($node, 'isCsrfTokenValid') ? Literals::true() : Unchanged::node();
    }

    public function hint(): Hint
    {
        return Hint::that('No test submits a wrong CSRF token and checks that it is refused.');
    }
}
