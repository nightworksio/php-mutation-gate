<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Subsumption;

use function array_keys;
use function array_reverse;
use function array_slice;
use function count;
use function is_int;
use function mb_strtolower;

use NightWorksIO\MutationGate\Core\Hint\Change;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Php\ComparisonOperator;
use NightWorksIO\MutationGate\Core\Php\OperandStop;

use function preg_match;

/**
 * The expression of its line a mutant negates, as its change's tokens show
 * it (ADR-0079): a `!` put before it, with or without brackets around it, a
 * `!` taken from before it, or a comparison swapped for its opposite, such as
 * `>` for `<=`, which negates the comparison around it.
 *
 * @internal
 */
final readonly class Negation
{
    private const string NOT = '!';

    private const string OPEN = '(';

    private const string CLOSE = ')';

    /** What a `!` put before an expression in brackets adds to its tokens. */
    private const array WRAPPING = [self::NOT, self::OPEN, self::CLOSE];

    /** A token an operand of `!` holds besides its brackets: a variable, a name, a number, a string or an accessor. */
    private const string OPERAND = '/^(\$|[A-Za-z_\\\\\x80-\xff]|[0-9]|[\'"]|->$|\?->$|::$)/';

    /**
     * Where the expression a mutant negates begins and ends among the
     * original's tokens, exclusive; none where it negates none.
     *
     * @return array{int, int}|NotGiven
     */
    public static function in(Change $change): array|NotGiven
    {
        $tokens = $change->tokens();
        [$before, $after] = [$tokens->before(), $tokens->after()];
        [$from, $to] = $tokens->differs();

        return match (count($after) - count($before)) {
            count(self::WRAPPING) => self::wrapped($before, $after),
            1 => self::put($before, $after, $from),
            -1 => self::taken($before, $after, $from),
            0 => self::opposite($change, $before, $after, $from, $to),
            default => NotGiven::value(),
        };
    }

    /**
     * The expression put in brackets behind a `!`, its brackets balanced:
     * of those the change may have put there, the one that begins last. The
     * tokens alike before and after the change may reach into what it
     * inserted, so the expression may begin before they first differ.
     *
     * @param  list<string>           $before
     * @param  list<string>           $after
     * @return array{int, int}|NotGiven
     */
    private static function wrapped(array $before, array $after): array|NotGiven
    {
        foreach (array_reverse(array_keys($after, self::NOT, strict: true)) as $start) {
            for ($end = $start + 1; $end <= count($before); ++$end) {
                $expression = array_slice($before, $start, $end - $start);
                $negated = [
                    ...array_slice($before, 0, $start),
                    self::NOT,
                    self::OPEN,
                    ...$expression,
                    self::CLOSE,
                    ...array_slice($before, $end),
                ];

                if ($negated === $after && Brackets::areBalanced($expression)) {
                    return [$start, $end];
                }
            }
        }

        return NotGiven::value();
    }

    /**
     * The operand a `!` was put before where the tokens first differ: where
     * the tokens alike reach into it, the `!` is as well put there.
     *
     * @param  list<string>           $before
     * @param  list<string>           $after
     * @return array{int, int}|NotGiven
     */
    private static function put(array $before, array $after, int $from): array|NotGiven
    {
        return [...array_slice($before, 0, $from), self::NOT, ...array_slice($before, $from)] === $after
            ? self::operand($before, $from, $from)
            : NotGiven::value();
    }

    /**
     * The `!` taken away where the tokens first differ, with the operand it
     * stood before.
     *
     * @param  list<string>           $before
     * @param  list<string>           $after
     * @return array{int, int}|NotGiven
     */
    private static function taken(array $before, array $after, int $from): array|NotGiven
    {
        return [...array_slice($after, 0, $from), self::NOT, ...array_slice($after, $from)] === $before
            ? self::operand($before, $from, $from + 1)
            : NotGiven::value();
    }

    /**
     * The comparison around a comparison swapped for its opposite.
     *
     * @param  list<string>           $before
     * @param  list<string>           $after
     * @return array{int, int}|NotGiven
     */
    private static function opposite(Change $change, array $before, array $after, int $from, int $to): array|NotGiven
    {
        $comparison = $to - $from === 1 ? ComparisonOperator::tryFrom($before[$from]) : null;
        $swapped = $comparison instanceof ComparisonOperator && $comparison->opposite()->value === $after[$from];

        return $swapped ? $change->tokens()->around() : NotGiven::value();
    }

    /**
     * The tokens from a `!` through the operand that begins at a token, where
     * there is one: a group in brackets, or a chain of variables, names,
     * accessors and the groups they call or index.
     *
     * @param  list<string>           $tokens
     * @return array{int, int}|NotGiven
     */
    private static function operand(array $tokens, int $start, int $from): array|NotGiven
    {
        $at = $from;
        $next = self::past($tokens, $at);

        while (is_int($next)) {
            $at = $next;
            $next = self::past($tokens, $at);
        }

        return $at > $from ? [$start, $at] : NotGiven::value();
    }

    /**
     * Where an operand goes on past the token at this place: after the group
     * a bracket there opens, or after a token an operand holds; nowhere at
     * its end or a token it stops at.
     *
     * @param list<string> $tokens
     */
    private static function past(array $tokens, int $at): int|NotGiven
    {
        $token = $at < count($tokens) ? $tokens[$at] : '';
        $closes = Brackets::opens($token) ? Brackets::closing($tokens, $at) : NotGiven::value();
        $holds = preg_match(self::OPERAND, $token) === 1
            && ! OperandStop::tryFrom(mb_strtolower($token)) instanceof OperandStop;

        return match (true) {
            is_int($closes) => $closes + 1,
            $holds => $at + 1,
            default => NotGiven::value(),
        };
    }
}
