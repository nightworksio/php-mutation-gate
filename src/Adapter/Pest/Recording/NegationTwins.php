<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use function file_get_contents;
use function in_array;
use function is_file;
use function is_string;
use function strlen;
use function substr;

/**
 * The rule that two mutants of one condition are one program (ADR-0137):
 * `RemoveNot` turns the condition `!E` of an `if` or a ternary into `E`, and
 * `IfNegated` or `TernaryNegated` turns it into `!!E`. Both branch on the
 * truthiness of `E`, evaluated once, for every value of `E`. Two such
 * mutants of one file and lines are one program where the negated one's copy
 * is the other's with `!!` set in at one place.
 *
 * @internal
 */
final readonly class NegationTwins
{
    private const string REMOVE_NOT = 'Pest\\Mutate\\Mutators\\Logical\\RemoveNot';

    private const array NEGATING = [
        'Pest\\Mutate\\Mutators\\ControlStructures\\IfNegated',
        'Pest\\Mutate\\Mutators\\ControlStructures\\TernaryNegated',
    ];

    private const string DOUBLED = '!!';

    /** Whether a mutator takes part in the rule. */
    public static function names(string $mutator): bool
    {
        return $mutator === self::REMOVE_NOT || in_array($mutator, self::NEGATING, strict: true);
    }

    /**
     * Whether two mutants of one file and lines, by their mutators and mutated copies, are one program by the rule.
     */
    public static function alike(string $mutator, string $copy, string $otherMutator, string $otherCopy): bool
    {
        $negating = in_array($mutator, self::NEGATING, strict: true);
        $otherNegating = in_array($otherMutator, self::NEGATING, strict: true);

        return match (true) {
            $mutator === self::REMOVE_NOT && $otherNegating
                => self::doubledIn(self::read($copy), self::read($otherCopy)),
            $negating && $otherMutator === self::REMOVE_NOT
                => self::doubledIn(self::read($otherCopy), self::read($copy)),
            default => false,
        };
    }

    /** Whether the negated text is the removed one with `!!` set in at one place, and nothing else apart. */
    private static function doubledIn(string $removed, string $negated): bool
    {
        if ($removed === '' || strlen($negated) !== strlen($removed) + strlen(self::DOUBLED)) {
            return false;
        }

        $at = 0;

        while ($at < strlen($removed) && $removed[$at] === $negated[$at]) {
            $at++;
        }

        return substr($negated, $at, strlen(self::DOUBLED)) === self::DOUBLED
            && substr($negated, $at + strlen(self::DOUBLED)) === substr($removed, $at);
    }

    private static function read(string $copy): string
    {
        $text = is_file($copy) ? file_get_contents($copy) : false;

        return is_string($text) ? $text : '';
    }
}
