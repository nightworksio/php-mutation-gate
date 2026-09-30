<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateDefault\String;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\DefaultSet;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\String_;

/** `$a . $b` becomes `$b . $a`, where the two sides differ. */
final readonly class ConcatSwitchSides implements Mutator
{
    public function name(): MutatorName
    {
        return DefaultSet::mutator('ConcatSwitchSides');
    }

    public function family(): MutatorFamily
    {
        return MutatorFamily::None;
    }

    public function tags(): Tags
    {
        return Tags::none();
    }

    public function handles(): NodeClasses
    {
        return NodeClasses::of(Concat::class);
    }

    public function mutate(Node $node): Node|Unchanged
    {
        return $node instanceof Concat && $this->differ($node->left, $node->right)
            ? new Concat($node->right, $node->left, $node->getAttributes())
            : Unchanged::node();
    }

    public function hint(): FamilyHint
    {
        return FamilyHint::ofItsFamily();
    }

    /** Whether two sides differ: of two kinds, or two variables, constants or strings of two names or values. */
    private function differ(Expr $left, Expr $right): bool
    {
        return match (true) {
            $left->getType() !== $right->getType() => true,
            $left instanceof ConstFetch && $right instanceof ConstFetch
                => $left->name->toString() !== $right->name->toString(),
            $left instanceof String_ && $right instanceof String_ => $left->value !== $right->value,
            $left instanceof Variable && $right instanceof Variable => $left->name !== $right->name,
            default => true,
        };
    }
}
