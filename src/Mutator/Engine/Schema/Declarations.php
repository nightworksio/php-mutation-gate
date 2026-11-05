<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine\Schema;

use function array_all;
use function is_int;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Label;
use PhpParser\NodeFinder;

/**
 * Whether the statements a schema puts inside an `if` declare nothing whose
 * meaning the `if` would change (ADR-0091): a function or a class, which an
 * `if` declares only once it runs, a label, which no `goto` may jump into a
 * block for, or a constant, an import or a namespace.
 *
 * @internal the engine's own
 */
final readonly class Declarations
{
    /** What may not stand inside a schema's `if`. */
    private const array DECLARED = [
        Function_::class,
        ClassLike::class,
        Label::class,
    ];

    /** Whether neither the original nor its replacement declares anything. */
    public static function noneIn(Node $original, Node|int $replacement): bool
    {
        $nodes = is_int($replacement) ? [$original] : [$original, $replacement];
        $finder = new NodeFinder();
        return array_all(
            self::DECLARED,
            static fn(string $declared): bool => ! $finder->findFirstInstanceOf($nodes, $declared) instanceof Node,
        );
    }
}
