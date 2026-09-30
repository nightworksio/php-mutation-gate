<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules\Mixed;

use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;

/** Whether a parameter, a return or a property is typed `mixed` natively, alone or in a union. */
final readonly class MixedInType
{
    private const string MIXED = 'mixed';

    public static function of(Node $node): bool
    {
        if (! $node instanceof Param && ! $node instanceof Property && ! $node instanceof FunctionLike) {
            return false;
        }

        $type = $node instanceof FunctionLike ? $node->getReturnType() : $node->type;

        return ($type instanceof Identifier || $type instanceof ComplexType)
            && new NodeFinder()->findFirst([$type], static fn(Node $part): bool => $part instanceof Identifier && $part->toLowerString() === self::MIXED) instanceof Node;
    }
}
