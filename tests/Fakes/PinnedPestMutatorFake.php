<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use Pest\Mutate\Contracts\Mutator;
use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Minus;
use PhpParser\Node\Expr\BinaryOp\Plus;

/** A Pest mutator, of a name no other test registers, that a test registers as a bridge pinned by source. */
final class PinnedPestMutatorFake implements Mutator
{
    /** @return list<class-string<Node>> */
    public static function nodesToHandle(): array
    {
        return [Plus::class];
    }

    public static function name(): string
    {
        return 'PinnedFake';
    }

    public static function set(): string
    {
        return 'Fake';
    }

    public static function can(Node $node): bool
    {
        return $node instanceof Plus;
    }

    public static function mutate(Node $node): Node
    {
        return $node instanceof Plus ? new Minus($node->left, $node->right) : $node;
    }
}
