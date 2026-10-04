<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateSecurity\Mutators;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateSecurity\Calls;
use NightWorksIO\MutationGateSecurity\SecuritySet;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;

use function str_starts_with;

/** `filter_var($x, FILTER_VALIDATE_…)` becomes `$x`. */
final readonly class FilterVarToInput implements Mutator
{
    /** How the name of every filter that validates starts. */
    private const string VALIDATE = 'FILTER_VALIDATE_';

    /** Where `filter_var()` takes the filter. */
    private const int FILTER = 1;

    public function name(): MutatorName
    {
        return SecuritySet::mutator('FilterVarToInput');
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
        $call = Calls::ofFunction($node, 'filter_var');
        $value = $call === false ? false : Calls::argument($call, 0);
        $filter = $call === false ? false : Calls::argument($call, self::FILTER);

        return $value instanceof Expr
            && $filter instanceof ConstFetch
            && str_starts_with($filter->name->toString(), self::VALIDATE)
            ? $value
            : Unchanged::node();
    }

    public function hint(): Hint
    {
        return Hint::that('No test passes invalid input and checks that it is refused.');
    }
}
