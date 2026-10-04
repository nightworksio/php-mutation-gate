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
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\FuncCall;

use function sprintf;

/**
 * `hash_equals($a, $b)` becomes `$a === $b`.
 *
 * The comparison behaves the same in every test and only its timing differs,
 * so only a test that reads the source, such as an arch test that expects
 * `hash_equals`, kills it.
 */
final readonly class HashEqualsToIdentical implements Mutator
{
    /** Where `hash_equals()` takes the string it compares the known one with. */
    private const int USER = 1;

    /** What a survivor misses, then the test that would kill it. */
    private const string PINNED = 'Only a test that reads the source can pin a constant-time comparison: %s';

    private const string ARCH_TEST = 'an arch test that expects `hash_equals`.';

    public function name(): MutatorName
    {
        return SecuritySet::mutator('HashEqualsToIdentical');
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
        $call = Calls::ofFunction($node, 'hash_equals');
        $known = $call === false ? false : Calls::argument($call, 0);
        $user = $call === false ? false : Calls::argument($call, self::USER);

        return $known instanceof Expr && $user instanceof Expr
            ? new Identical($known, $user)
            : Unchanged::node();
    }

    public function hint(): Hint
    {
        return Hint::that(sprintf(self::PINNED, self::ARCH_TEST));
    }
}
