<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateSecurity\Mutators;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\SourcePin;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\PinnedBySource;
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
 * so only a test that runs it and then reads its file, expecting
 * `hash_equals(`, kills it.
 */
final readonly class HashEqualsToIdentical implements Mutator, PinnedBySource
{
    /** The constant-time comparison it changes. */
    private const string FUNCTION = 'hash_equals';

    /** Where `hash_equals()` takes the string it compares the known one with. */
    private const int USER = 1;

    /** What a survivor misses, then the test that would kill it. */
    private const string PINNED = 'Only a test that reads the source can pin a constant-time comparison: %s';

    private const string READING_TEST = 'one that runs it, then asserts that its file still calls `hash_equals`.';

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
        $call = Calls::ofFunction($node, self::FUNCTION);
        $known = $call === false ? false : Calls::argument($call, 0);
        $user = $call === false ? false : Calls::argument($call, self::USER);

        return $known instanceof Expr && $user instanceof Expr
            ? new Identical($known, $user)
            : Unchanged::node();
    }

    public function pin(): SourcePin
    {
        return SourcePin::call(self::FUNCTION);
    }

    public function hint(): Hint
    {
        return Hint::that(sprintf(self::PINNED, self::READING_TEST));
    }
}
