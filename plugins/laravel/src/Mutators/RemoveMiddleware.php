<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateLaravel\Mutators;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Ancestors;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Removal;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateLaravel\Calls;
use NightWorksIO\MutationGateLaravel\LaravelSet;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;

/** `$this->middleware(…)` in a constructor is removed, as a controller's is. */
final readonly class RemoveMiddleware implements Mutator
{
    public function name(): MutatorName
    {
        return LaravelSet::mutator('RemoveMiddleware');
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
        $method = Ancestors::nearest($node, ClassMethod::class);

        return $node instanceof Expression
            && Calls::onThis($node->expr, 'middleware')
            && $method instanceof ClassMethod
            && $method->name->toLowerString() === '__construct'
            ? Removal::statement()
            : Unchanged::node();
    }

    public function hint(): Hint
    {
        return Hint::that('No test sends a request this middleware refuses.');
    }
}
