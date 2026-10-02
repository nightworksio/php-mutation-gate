<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function count;

/**
 * A closure written in place, `function (…) use (…) { … }` or `fn (…) =>
 * …`, static or not, by reference or not, with or without attributes, read
 * from where it starts among a run of tokens: where its body opens and where
 * it ends. None starts at an index that begins anything else.
 */
final readonly class ClosureLiteral
{
    /**
     * @param int $opens where its body opens, at the `{` or the `=>`, or none
     * @param int $end   where it ends, past its last token, or none
     */
    private function __construct(private bool $arrow, private int $opens, private int $end)
    {
    }

    /** The closure that starts at an index, or none. */
    public static function at(Tokens $read, int $at): self
    {
        $keyword = self::keywordFrom($read, $at);
        $arrow = $read->is($keyword, T_FN);
        $opens = $keyword === Tokens::NONE ? Tokens::NONE : self::bodyFrom($read, $keyword, $arrow);

        return match (true) {
            $opens === Tokens::NONE => new self(arrow: false, opens: Tokens::NONE, end: Tokens::NONE),
            $arrow => new self(arrow: true, opens: $opens, end: self::expressionEnd($read, $opens)),
            default => new self(arrow: false, opens: $opens, end: $read->closing($opens) + 1),
        };
    }

    /** Whether a closure starts there. */
    public function isOne(): bool
    {
        return $this->end !== Tokens::NONE;
    }

    /** Whether it is an arrow function, whose body is one expression. */
    public function isArrow(): bool
    {
        return $this->arrow;
    }

    /** Where its body opens: at the `{` of a function, or the `=>` of an arrow function. */
    public function opens(): int
    {
        return $this->opens;
    }

    /** Where it ends, past its last token. */
    public function end(): int
    {
        return $this->end;
    }

    /** Where the `function` or `fn` of a closure that starts at an index stands, past attributes and `static`. */
    private static function keywordFrom(Tokens $read, int $at): int
    {
        while ($read->is($at, T_ATTRIBUTE)) {
            $at = $read->closing($at) + 1;
        }

        $at = $read->is($at, T_STATIC) ? $at + 1 : $at;

        return $read->is($at, T_FUNCTION, T_FN) ? $at : Tokens::NONE;
    }

    /**
     * Where the body of the closure whose keyword stands at an index opens,
     * past its parameters, what it uses and its return type; none where the
     * tokens are no closure.
     */
    private static function bodyFrom(Tokens $read, int $keyword, bool $arrow): int
    {
        $next = self::signatureEnd($read, $keyword);

        while ($next !== Tokens::NONE && $next < count($read) && ! $read->is($next, $arrow ? T_DOUBLE_ARROW : '{')) {
            $next = $read->is($next, '(') ? $read->closing($next) + 1 : $next + 1;
        }

        return $next < count($read) ? $next : Tokens::NONE;
    }

    /**
     * Where the parameters, and what the closure uses, end, past the keyword at an index; none where no
     * parameters follow it.
     */
    private static function signatureEnd(Tokens $read, int $keyword): int
    {
        $parameters = $read->is($keyword + 1, '&') ? $keyword + 2 : $keyword + 1;
        $next = $read->is($parameters, '(') ? $read->closing($parameters) + 1 : Tokens::NONE;

        return $read->is($next, T_USE) && $read->is($next + 1, '(') ? $read->closing($next + 1) + 1 : $next;
    }

    /** Where an arrow function's expression, after its `=>` at an index, ends: at a comma, or its bracket's end. */
    private static function expressionEnd(Tokens $read, int $arrow): int
    {
        $level = $read->enclosing($arrow);
        $counter = count($read);

        for ($at = $arrow + 1; $at < $counter; ++$at) {
            if ($read->enclosing($at) === $level && $read->is($at, ',', ';', ')', ']', '}')) {
                return $at;
            }
        }

        return count($read);
    }
}
