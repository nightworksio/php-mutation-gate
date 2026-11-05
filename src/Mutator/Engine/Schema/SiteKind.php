<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine\Schema;

use function sprintf;

/**
 * How a mutant takes its place in a schema (ADR-0091): an expression switched
 * by a ternary, a statement switched by an `if`, or an item of an array
 * switched by a spread, each holding the mutated code and the original, so
 * the file reads as the original wherever no mutant is switched on.
 *
 * @internal the engine's own
 */
enum SiteKind
{
    case Expression;
    case Statement;
    case ArrayItem;

    /** The switch in the original's place, the mutant this id names in force where it is on. */
    public function switched(string $switch, int $id, string $mutated, string $original): string
    {
        return match ($this) {
            self::Expression => sprintf('(%s(%d) ? (%s) : (%s))', $switch, $id, $mutated, $original),
            self::Statement => sprintf('if (%s(%d)) { %s } else { %s }', $switch, $id, $mutated, $original),
            self::ArrayItem => sprintf('...(%s(%d) ? [%s] : [%s])', $switch, $id, $mutated, $original),
        };
    }
}
