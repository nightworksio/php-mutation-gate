<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hint;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\NotGiven;

use function preg_match;
use function preg_quote;
use function sprintf;

/**
 * What an operand of a mutant's expression stands for, as far as rules can
 * read it (ADR-0101): a variable, a literal as it is written, and an integer,
 * written out or the value of a class constant its file declares with one
 * (ADR-0037).
 *
 * @internal
 */
final readonly class Values
{
    private const string VARIABLE = '/^\$\w+$/u';

    private const string INTEGER = '/^-?\d+$/u';

    private const string LITERAL = '/^(?:-?\d+(?:\.\d+)?|\'[^\'\\\\]*\'|"[^"\\\\$]*"|true|false|null)$/iu';

    private const string CONSTANT = '/^(?:self|static|[A-Z]\w*)::([A-Z][A-Z0-9_]*)$/u';

    private const string DECLARED = '/\bconst\s+(?:\??\w+\s+)?%s\s*=\s*(-?\d+)\s*[;,]/u';

    public static function isVariable(string $operand): bool
    {
        return preg_match(self::VARIABLE, $operand) === 1;
    }

    /** A literal as it is written: a number, a string, `true`, `false` or `null`; none for anything else. */
    public static function literal(string $operand): string|NotGiven
    {
        return preg_match(self::LITERAL, $operand) === 1 ? $operand : NotGiven::value();
    }

    /** The integer an operand stands for, written out or a constant its file declares with one; none otherwise. */
    public static function integer(string $operand, Contents|Missing $source): int|NotGiven
    {
        if (preg_match(self::INTEGER, $operand) === 1) {
            return (int) $operand;
        }

        if (! $source instanceof Contents || preg_match(self::CONSTANT, $operand, $constant) !== 1) {
            return NotGiven::value();
        }

        $declared = sprintf(self::DECLARED, preg_quote($constant[1], '/'));

        return preg_match($declared, $source->text(), $value) === 1 ? (int) $value[1] : NotGiven::value();
    }
}
