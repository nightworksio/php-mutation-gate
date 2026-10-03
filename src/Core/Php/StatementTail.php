<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function max;

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * The lines a statement inside a function's body spans past its first, where
 * a token stands on that first line: PHP runs a statement's first line before
 * any other, so a test that runs one of the others ran it. Coverage may never
 * mark that first line run, as pcov never marks the head of a `match (true)`.
 * A line that starts no statement, such as a `match` arm's, may never run
 * while the lines around it do, and has no tail.
 */
final readonly class StatementTail
{
    /** What stands before a `{` that opens a name in braces, as in `$object->{$name}`, rather than a block. */
    private const array NOT_A_BLOCK = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, '$'];

    private function __construct(private Line $first, private Line $last)
    {
    }

    /**
     * The tail of the statement the line of a token starts, or none where
     * that line starts none or the statement spans that line alone.
     */
    public static function of(Source $source, int $at): self|NotGiven
    {
        $tokens = $source->tokens();
        $line = $tokens->line($at);
        $start = $at;

        while ($start > 0 && $tokens->line($start - 1) === $line) {
            $start--;
        }

        $last = self::lastLine($tokens, $start, $line);

        return self::startsAStatement($source, $start) && $last > $line
            ? new self(Line::of($line + 1), Line::of($last))
            : NotGiven::value();
    }

    /** The first line past the statement's own. */
    public function first(): Line
    {
        return $this->first;
    }

    /** The last line the statement spans. */
    public function last(): Line
    {
        return $this->last;
    }

    /** Whether the token at an index starts a statement in a block of a function's body. */
    private static function startsAStatement(Source $source, int $start): bool
    {
        $tokens = $source->tokens();
        $block = $tokens->enclosing($start);

        return $start > 0
            && $tokens->is($start - 1, ...Tokens::STATEMENT_BOUNDS)
            && ! $tokens->is($start, '}')
            && self::holdsStatements($source, $block)
            && self::inABody($source, $block);
    }

    /** Whether a bracket is a block of statements: a `{` that opens no class body, `match` arms or a member's name. */
    private static function holdsStatements(Source $source, int $opener): bool
    {
        $tokens = $source->tokens();
        $after = $tokens->is($opener - 1, ')') ? $tokens->enclosing($opener - 1) - 1 : Tokens::NONE;

        return $tokens->is($opener, '{')
            && ! $source->shape()->isClassBody($opener)
            && ! $tokens->is($opener - 1, ...self::NOT_A_BLOCK)
            && ! $tokens->is($after, T_MATCH);
    }

    /** Whether a bracket is a function's body, or stands inside one. */
    private static function inABody(Source $source, int $opener): bool
    {
        $tokens = $source->tokens();

        for ($at = $opener; $at !== Tokens::NONE; $at = $tokens->enclosing($at)) {
            if ($source->shape()->isBody($at)) {
                return true;
            }
        }

        return false;
    }

    /** The last line a bracket that opens on a line closes on, or the line itself where every one closes there. */
    private static function lastLine(Tokens $tokens, int $start, int $line): int
    {
        $last = $line;

        for ($at = $start; $at < $tokens->count() && $tokens->line($at) === $line; $at++) {
            $opens = $tokens->is($at, '(', '[', '{') && $tokens->closing($at) < $tokens->count();
            $last = $opens ? max($last, $tokens->line($tokens->closing($at))) : $last;
        }

        return $last;
    }
}
