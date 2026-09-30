<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_filter;
use function array_values;
use function ltrim;

/**
 * What a token of a file stands in, read from the brackets around it: an
 * attribute's argument, a parameter's default, or a class member's value,
 * which coverage cannot see run. Anything inside a function's body, and
 * anything outside every class, is executable.
 */
final readonly class SymbolAt
{
    private function __construct(private Tokens $tokens, private Shape $shape)
    {
    }

    public static function in(Tokens $tokens, Shape $shape): self
    {
        return new self($tokens, $shape);
    }

    /** What the token at an index stands in: a value coverage cannot see run, or executable code. */
    public function token(int $at): Symbol|Unnamed|Executable
    {
        return $at < 0 || $at >= $this->tokens->count() ? Executable::line() : $this->around($at);
    }

    /** What the innermost bracket that decides it makes of a token. */
    private function around(int $at): Symbol|Unnamed|Executable
    {
        for ($child = $at; $this->tokens->isEnclosed($child); $child = $this->tokens->enclosing($child)) {
            $opener = $this->tokens->enclosing($child);

            if ($this->decides($opener)) {
                return $this->decided($opener, $child);
            }
        }

        return Executable::line();
    }

    private function decides(int $opener): bool
    {
        return $this->tokens->is($opener, T_ATTRIBUTE)
            || $this->shape->isBody($opener)
            || $this->shape->isSignature($opener)
            || $this->shape->isClassBody($opener);
    }

    /** What a bracket that decides makes of the token directly inside it that leads to the line. */
    private function decided(int $opener, int $child): Symbol|Unnamed|Executable
    {
        return match (true) {
            $this->tokens->is($opener, T_ATTRIBUTE) => Unnamed::of(SymbolKind::AttributeArgument),
            $this->shape->isBody($opener) => Executable::line(),
            $this->shape->isSignature($opener) => $this->defaultIn($opener, $child),
            default => $this->member($opener, $child),
        };
    }

    /** The parameter whose default a token of a parameter list stands in, if it stands in one. */
    private function defaultIn(int $list, int $child): Symbol|Unnamed|Executable
    {
        foreach ($this->direct($list, $child) as $at) {
            if ($this->tokens->is($at, ',', '=')) {
                return $this->tokens->is($at, '=')
                    ? $this->shape->signatureOf($list)->defaultOf($this->nameBefore($list, $at))
                    : Executable::line();
            }
        }

        return Executable::line();
    }

    /** The class member whose value a token directly inside a class-like body stands in, if any. */
    private function member(int $body, int $child): Symbol|Unnamed|Executable
    {
        $statement = $this->statement($body, $child);
        $equals = $this->first($statement, '=');
        $owner = $this->shape->classOf($body)->name();

        return match (true) {
            $equals === Tokens::NONE || $this->first($statement, T_FUNCTION) !== Tokens::NONE => Executable::line(),
            $this->first($statement, T_CONST) !== Tokens::NONE => Symbol::constant(
                $owner,
                $this->tokens->text($equals - 1),
            ),
            $this->first($statement, T_CASE) !== Tokens::NONE => Symbol::enumCase(
                $owner,
                $this->tokens->text($this->first($statement, T_CASE) + 1),
            ),
            $this->first($statement, T_VARIABLE) !== Tokens::NONE => Symbol::property(
                $owner,
                ltrim($this->tokens->text($this->first($statement, T_VARIABLE)), '$'),
                static: $this->first($statement, T_STATIC) !== Tokens::NONE,
            ),
            default => Executable::line(),
        };
    }

    /**
     * The tokens directly inside a bracket from one of them back to where the
     * bracket opens, nearest first.
     *
     * @return list<int>
     */
    private function direct(int $opener, int $from): array
    {
        $direct = [];

        for ($at = $from; $at > $opener; $at--) {
            $direct = $this->tokens->enclosing($at) === $opener ? [...$direct, $at] : $direct;
        }

        return $direct;
    }

    /**
     * The tokens of the statement a token directly inside a class-like body
     * belongs to, from it back to where the statement begins, nearest first.
     *
     * @return list<int>
     */
    private function statement(int $body, int $child): array
    {
        $statement = [];

        foreach ($this->direct($body, $child) as $at) {
            if ($this->tokens->is($at, ';') || $this->tokens->is($at, '{') && $at !== $child) {
                return $statement;
            }

            $statement[] = $at;
        }

        return $statement;
    }

    /** The name of the parameter whose default begins after an `=` of a parameter list, without its `$`. */
    private function nameBefore(int $list, int $equals): string
    {
        $variables = array_values(array_filter(
            $this->direct($list, $equals),
            fn(int $at): bool => $this->tokens->is($at, T_VARIABLE),
        ));

        return $variables === [] ? '' : ltrim($this->tokens->text($variables[0]), '$');
    }

    /**
     * The nearest of a statement's tokens of a kind.
     *
     * @param list<int> $statement
     */
    private function first(array $statement, int|string $kind): int
    {
        foreach ($statement as $at) {
            if ($this->tokens->is($at, $kind)) {
                return $at;
            }
        }

        return Tokens::NONE;
    }
}
