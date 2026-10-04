<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateSecurity\Mutators;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Literals;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateSecurity\Calls;
use NightWorksIO\MutationGateSecurity\SecuritySet;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;

/** `password_verify(…)` becomes `true`. */
final readonly class PasswordVerifyToTrue implements Mutator
{
    public function name(): MutatorName
    {
        return SecuritySet::mutator('PasswordVerifyToTrue');
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
        return NodeClasses::of(FuncCall::class);
    }

    public function mutate(Node $node): Node|Unchanged
    {
        return Calls::ofFunction($node, 'password_verify') instanceof FuncCall ? Literals::true() : Unchanged::node();
    }

    public function hint(): Hint
    {
        return Hint::that('No test checks that `password_verify()` refuses a wrong password.');
    }
}
