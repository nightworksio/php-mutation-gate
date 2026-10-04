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
            $body = $tokens->is($match + 1, '(') ? $tokens->closing($match + 1) + 1 : Tokens::NONE;

            if ($tokens->is($body, '{') && $match <= $at && $at <= $body) {
                return self::inside($tokens, $body);
            }
        }

        return NotGiven::value();
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

    /**
     * The lines past the one a brace opens on, up to the one it closes on;
     * none where it closes on its own line, or never does.
     */
    private static function inside(Tokens $tokens, int $opener): self|NotGiven
    {
        $closer = $tokens->closing($opener);
        $first = $tokens->line($opener) + 1;
        $last = $tokens->is($closer, '}') ? $tokens->line($closer) : $first - 1;

        return $last >= $first ? new self(Line::of($first), Line::of($last)) : NotGiven::value();
    }
}
