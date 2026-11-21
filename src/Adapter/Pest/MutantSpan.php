<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_flip;
use function array_key_exists;

use PhpParser\Node;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassMethod;

/**
 * The lines a Pest mutant of a node spans (ADR-0307): from the node's first
 * line to its last, or to its method's last where the node is part of a
 * method's parameter. Pest records these lines, selects the mutant's
 * covering tests by them, and the verdict expects the mutant of every part
 * whose made lines they touch; a part's run makes it by them too.
 *
 * @internal
 */
final readonly class MutantSpan
{
    /** The last line a mutant of this node spans. */
    public static function lastOf(Node $node): int
    {
        $parent = $node->getAttribute('parent');
        $method = $parent instanceof Param ? $parent->getAttribute('parent') : null;

        return $method instanceof ClassMethod ? $method->getEndLine() : $node->getEndLine();
    }

    /**
     * Whether a mutant of this node spans one of these lines.
     *
     * @param array<int, int> $lines
     */
    public static function touches(Node $node, array $lines): bool
    {
        $wanted = array_flip($lines);
        $last = self::lastOf($node);

        for ($line = $node->getStartLine(); $line <= $last; $line++) {
            if (array_key_exists($line, $wanted)) {
                return true;
            }
        }

        return false;
    }
}
