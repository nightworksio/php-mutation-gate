<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Contract\Runner\Mutators;

use function count;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\SourcePin;
use NightWorksIO\MutationGate\Mutator\FamilyHint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorName;
use NightWorksIO\MutationGate\Mutator\NodeClasses;
use NightWorksIO\MutationGate\Mutator\PinnedBySource;
use NightWorksIO\MutationGate\Mutator\Tags;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;

/**
 * The registered mutator the runner contract runs on a constant-time
 * comparison: `hash_equals($known, $user)` becomes `$known === $user`, which
 * every test finds the same, so only a test that reads the file kills it
 * (ADR-0021).
 */
final readonly class PinnedIdentical implements Mutator, PinnedBySource
{
    private const string FUNCTION = 'hash_equals';

    public function name(): MutatorName
    {
        return MutatorName::of('contract', 'PinnedIdentical');
    }

    public function family(): MutatorFamily
    {
        return MutatorFamily::Condition;
    }

    public function tags(): Tags
    {
        return Tags::none();
    }

    public function handles(): NodeClasses
    {
        return NodeClasses::of(FuncCall::class);
    }

    public function mutate(Node $node): Node|Unchanged
    {
        $named = $node instanceof FuncCall && $node->name instanceof Name;

        if (! $named || $node->name->toLowerString() !== self::FUNCTION) {
            return Unchanged::node();
        }

        $arguments = $node->getArgs();

        return count($arguments) === 2 ? new Identical($arguments[0]->value, $arguments[1]->value) : Unchanged::node();
    }

    public function pin(): SourcePin
    {
        return SourcePin::call(self::FUNCTION);
    }

    public function hint(): FamilyHint
    {
        return FamilyHint::ofItsFamily();
    }
}
