<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateLaravel\Mutators;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateLaravel\Calls;
use NightWorksIO\MutationGateLaravel\LaravelSet;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;

/** `e($x)` becomes `$x`. */
final readonly class UnwrapEscape implements Mutator
{
    public function name(): MutatorName
    {
        return LaravelSet::mutator('UnwrapEscape');
    }

    public function family(): MutatorFamily
    {
        return MutatorFamily::Unwrap;
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
        $escaped = $node instanceof FuncCall && Calls::ofFunction($node, 'e') ? Calls::argument($node, 0) : false;

        return $escaped instanceof Node ? $escaped : Unchanged::node();
    }

    public function hint(): Hint
    {
        return Hint::that('No test passes markup through `e()` and checks that it comes out escaped.');
    }
}
