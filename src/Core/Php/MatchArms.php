<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * The lines inside the braces of a `match` whose head holds a token: the
 * `match` keyword, its subject in parentheses, and the `{` that opens its
 * arms. PHP evaluates a match's subject before any arm, so a test that runs
 * a line of its arms ran its head, wherever the match stands: as a
 * statement, inside an argument list, after a ternary's `?`, or in another
 * match's arm. Coverage may never mark that head run, as pcov never marks
 * the head of a `match (true)`.
 *
 * @internal
 */
final readonly class MatchArms
{
    private function __construct(private Line $first, private Line $last)
    {
    }

    /**
     * The arms of the match whose head holds the token at an index, or none
     * where no match's head holds it or its arms close on the line its head
     * opens them on.
     */
    public static function of(Tokens $tokens, int $at): self|NotGiven
    {
        foreach ($tokens->indicesOf(T_MATCH) as $match) {
            $body = self::bodyOf($tokens, $match);

            if ($body !== Tokens::NONE && $match <= $at && $at <= $body) {
                return self::inside($tokens, $body);
            }
        }

        return NotGiven::value();
    }

    /**
     * The arms of every match in a file whose arms close past the line its
     * head opens them on.
     *
     * @return list<self>
     */
    public static function in(Tokens $tokens): array
    {
        $every = [];

        foreach ($tokens->indicesOf(T_MATCH) as $match) {
            $body = self::bodyOf($tokens, $match);
            $arms = $body === Tokens::NONE ? NotGiven::value() : self::inside($tokens, $body);
            $every = $arms instanceof self ? [...$every, $arms] : $every;
        }

        return $every;
    }

    /** The first line past the one the arms open on. */
    public function first(): Line
    {
        return $this->first;
    }

    /** The line the arms close on. */
    public function last(): Line
    {
        return $this->last;
    }

    /** Where the arms of the match whose keyword stands at an index open; nowhere where it has none. */
    private static function bodyOf(Tokens $tokens, int $match): int
    {
        $body = $tokens->is($match + 1, '(') ? $tokens->closing($match + 1) + 1 : Tokens::NONE;

        return $tokens->is($body, '{') ? $body : Tokens::NONE;
    }

    /**
     * The lines past the one a brace opens on, up to the one it closes on;
     * none where it closes on its own line, or never does.
     */
    private static function inside(Tokens $tokens, int $opener): self|NotGiven
    {
        $closer = $tokens->closing($opener);
        $first = $tokens->line($opener) + 1;

        return $tokens->is($closer, '}') && $tokens->line($closer) >= $first
            ? new self(Line::of($first), Line::of($tokens->line($closer)))
            : NotGiven::value();
    }
}
