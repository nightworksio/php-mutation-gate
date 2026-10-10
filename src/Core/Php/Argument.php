<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_slice;
use function count;
use function ord;

use PhpToken;

/**
 * One argument of a call, as its significant tokens: a closure written in
 * place, or an expression, which may run nothing when it is evaluated or may
 * run code. A closure runs nothing where it stands; it runs when it is called.
 */
final readonly class Argument
{
    /** A literal: a quoted string with no variable in it, a number, a heredoc or nowdoc, or a magic constant. */
    private const array LITERALS = [
        T_CONSTANT_ENCAPSED_STRING,
        T_LNUMBER,
        T_DNUMBER,
        T_START_HEREDOC,
        T_ENCAPSED_AND_WHITESPACE,
        T_END_HEREDOC,
        T_DIR,
        T_FILE,
        T_LINE,
        T_NS_C,
    ];

    /** What combines values and writes nothing: arithmetic, comparison, logic, joining, and an array's parts. */
    private const array OPERATORS = [
        '.',
        '+',
        '-',
        '*',
        '/',
        '%',
        '!',
        '~',
        '&',
        '|',
        '^',
        '<',
        '>',
        '?',
        ':',
        ',',
        '[',
        T_POW,
        T_IS_EQUAL,
        T_IS_IDENTICAL,
        T_IS_NOT_EQUAL,
        T_IS_NOT_IDENTICAL,
        T_IS_SMALLER_OR_EQUAL,
        T_IS_GREATER_OR_EQUAL,
        T_SPACESHIP,
        T_BOOLEAN_AND,
        T_BOOLEAN_OR,
        T_LOGICAL_AND,
        T_LOGICAL_OR,
        T_LOGICAL_XOR,
        T_COALESCE,
        T_SL,
        T_SR,
        T_DOUBLE_ARROW,
        T_ELLIPSIS,
        T_ARRAY,
        T_DOUBLE_COLON,
    ];

    /** What ends an operand, so that a `(` after it calls what it ends. */
    private const array OPERAND_ENDS = [')', ']', T_CLASS];

    /** What hands a closure's or a generator's caller a value. */
    private const array GIVES = [T_RETURN, T_YIELD, T_YIELD_FROM];

    /** What ends a statement, which an arrow function's expression is read as. */
    private const string END = ';';

    /** @param list<PhpToken> $tokens */
    private function __construct(private array $tokens, private Tokens $read)
    {
    }

    /** @param list<PhpToken> $tokens the argument's significant tokens, in order */
    public static function of(array $tokens): self
    {
        return new self($tokens, Tokens::of($tokens));
    }

    /** Whether the argument is one closure written in place, and nothing more. */
    public function isClosure(): bool
    {
        return $this->tokens !== [] && ClosureLiteral::at($this->read, 0)->end() === count($this->tokens);
    }

    /**
     * The statements of the closure's body, an arrow function's expression
     * read as one; none for an argument that is not a closure.
     */
    public function body(): TopLevel
    {
        $closure = ClosureLiteral::at($this->read, 0);
        $after = array_slice($this->tokens, $closure->opens() + 1);

        return match (true) {
            ! $this->isClosure() => TopLevel::of([]),
            $closure->isArrow() => TopLevel::of([...$after, new PhpToken(ord(self::END[0]), self::END)]),
            default => TopLevel::of(array_slice($after, 0, -1)),
        };
    }

    /**
     * Whether the argument is a closure that only hands back what runs
     * nothing: an arrow function whose expression runs nothing, or a function
     * whose every statement returns or yields such a value, and that declares
     * nothing.
     */
    public function onlyGives(OwnVariables $own): bool
    {
        $closure = ClosureLiteral::at($this->read, 0);

        return match (true) {
            ! $this->isClosure() => false,
            $closure->isArrow() => self::of(array_slice($this->tokens, $closure->opens() + 1))->runsNothing($own),
            default => $this->givesOnly($this->body(), $own),
        };
    }

    /**
     * Whether evaluating the argument runs nothing: literals, constants,
     * `::class` and class constants, arrays of these, these variables of the
     * scope's own, the operators that combine them, and closures, which run
     * only when called. A call, another variable, an assignment, `new`, an
     * include and anything else run code.
     */
    public function runsNothing(OwnVariables $own): bool
    {
        $operand = false;
        $at = 0;

        while ($at < count($this->tokens)) {
            $closure = ClosureLiteral::at($this->read, $at);
            $owned = $own->has($this->tokens[$at]);

            if (! $closure->isOne() && ! $owned && ! $this->evaluatesNothing($at, $operand)) {
                return false;
            }

            $operand = $closure->isOne()
                || $owned
                || $this->read->is($at, ...self::LITERALS, ...Names::TOKENS, ...self::OPERAND_ENDS);
            $at = $closure->isOne() ? $closure->end() : $at + 1;
        }

        return true;
    }

    /** Whether a closure's body declares nothing, and each of its statements returns or yields what runs nothing. */
    private function givesOnly(TopLevel $body, OwnVariables $own): bool
    {
        if ($body->declared() !== []) {
            return false;
        }

        foreach ($body->running() as $statement) {
            $value = array_slice($statement, 1, -1);
            $gives = $statement[0]->is(self::GIVES) && $statement[count($statement) - 1]->is(self::END);

            if (! $gives || $value === [] || ! self::of($value)->runsNothing($own)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the token at an index, after an operand or not, evaluates without running code: a `(` after an
     * operand calls it.
     */
    private function evaluatesNothing(int $at, bool $afterOperand): bool
    {
        return $this->read->is($at, '(')
            ? ! $afterOperand
            : $this->read->is($at, ...self::LITERALS, ...Names::TOKENS, ...self::OPERATORS, ...self::OPERAND_ENDS);
    }
}
