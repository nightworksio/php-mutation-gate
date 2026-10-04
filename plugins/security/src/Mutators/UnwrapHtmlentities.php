<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateSecurity\Mutators;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\NamedMutators;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\SameChange;
use NightWorksIO\MutationGate\Mutator\Tag;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateSecurity\Calls;
use NightWorksIO\MutationGateSecurity\OtherUnwraps;
use NightWorksIO\MutationGateSecurity\SecuritySet;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;

/**
 * `htmlentities($x, …)` becomes `$x`, where neither the `default` set nor Pest's own
 * set runs to make that change.
 */
final readonly class UnwrapHtmlentities implements Mutator, SameChange
{
    private const string OWN = 'UnwrapHtmlentities';

    public function name(): MutatorName
    {
        return SecuritySet::mutator(self::OWN);
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

    public function mutate(Node $node): Expr|Unchanged
    {
        return Calls::unwrapped($node, 'htmlentities');
    }

    public function hint(): Hint
    {
        return Hint::that('No test passes markup and checks that `htmlentities()` escapes it.');
    }

    public function madeAlsoBy(): NamedMutators
    {
        return OtherUnwraps::named(self::OWN);
    }
}
