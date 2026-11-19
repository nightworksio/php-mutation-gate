<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine\Schema;

use function sprintf;

/**
 * How a mutant takes its place in a schema (ADR-0091): an expression switched
 * by a ternary, a statement switched by an `if`, an item of an array
 * switched by a spread, or a pure expression switched by a call that takes
 * both as closures, where a split stream checks the change (ADR-0117), each
 * holding the mutated code and the original, so the file reads as the
 * original wherever no mutant is switched on.
 *
 * @internal the engine's own
 */
enum SiteKind
{
    case Expression;
    case Statement;
    case ArrayItem;
    case Value;

    /**
     * The switch in the original's place, the mutant this id names in force
     * where it is on: the switch is a call, `%d` where it takes the id.
     */
    public function switched(SwitchCall $switch, int $id, string $mutated, string $original): string
    {
        $on = $switch->on($id);

        return match ($this) {
            self::Expression => sprintf('(%s ? (%s) : (%s))', $on, $mutated, $original),
            self::Statement => sprintf('if (%s) { %s } else { %s }', $on, $mutated, $original),
            self::ArrayItem => sprintf('...(%s ? [%s] : [%s])', $on, $mutated, $original),
            self::Value => $switch->valued($id, $original, $mutated),
        };
    }
}
