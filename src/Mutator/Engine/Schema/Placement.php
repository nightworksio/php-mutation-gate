<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine\Schema;

use function array_any;
use function intval;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Mutator\Mutator;
use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Break_;
use PhpParser\Node\Stmt\Continue_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\Echo_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Nop;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\Node\Stmt\While_;

use function sprintf;

/**
 * Where a mutant can be switched in a schema (ADR-0091), and how: an
 * expression read where any expression may stand, a statement of a body, or
 * an item of an array read as a value whose key the spread keeps. Anywhere
 * else, a constant expression, a default, an attribute, a signature, a
 * place written to or passed by reference, a string's interpolation or a
 * declaration, the mutant keeps a file of its own.
 *
 * @internal the engine's own
 */
final readonly class Placement
{
    /** The statements a schema switches with an `if`. */
    private const array STATEMENTS = [
        Expression::class,
        Return_::class,
        Echo_::class,
        If_::class,
        While_::class,
        Do_::class,
        For_::class,
        Foreach_::class,
        Switch_::class,
        TryCatch::class,
        Unset_::class,
        Nop::class,
        Break_::class,
        Continue_::class,
    ];

    /** How a site takes its place in a schema; none where it cannot. */
    public static function of(Site $site): SiteKind|NotGiven
    {
        $original = $site->original();
        $replacement = $site->replacement();

        return match (true) {
            $original instanceof Expr => $replacement instanceof Expr && Readers::admit($original)
                ? SiteKind::Expression
                : NotGiven::value(),
            $original instanceof Stmt => self::statement($original, $replacement),
            $original instanceof ArrayItem => self::item($original),
            default => NotGiven::value(),
        };
    }

    private static function statement(Stmt $original, Node|int $replacement): SiteKind|NotGiven
    {
        return self::isSwitchable($original) && Declarations::noneIn($original, $replacement)
            ? SiteKind::Statement
            : NotGiven::value();
    }

    private static function item(ArrayItem $original): SiteKind|NotGiven
    {
        $parent = $original->getAttribute(Mutator::PARENT);

        return ! $original->byRef && self::keepsItsKey($original)
            && $parent instanceof Array_ && Readers::admit($parent)
            ? SiteKind::ArrayItem
            : NotGiven::value();
    }

    private static function isSwitchable(Stmt $statement): bool
    {
        return array_any(self::STATEMENTS, static fn(string $kind): bool => $statement instanceof $kind);
    }

    /** Whether a spread keeps the item's key: none, or text no integer reads as. */
    private static function keepsItsKey(ArrayItem $item): bool
    {
        $key = $item->key;

        return !$key instanceof Expr || ($key instanceof String_ && sprintf('%d', intval($key->value)) !== $key->value);
    }
}
