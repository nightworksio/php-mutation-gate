<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_filter;
use function array_values;
use function min;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use PhpToken;

/**
 * A PHP file of the project, read by its tokens for what reads a value it or
 * another file declares: a test, which judges a mutant when it reads it, or a
 * source file, whose line that reads it is judged by the tests that cover it.
 */
final readonly class Source
{
    /** What stands before a name that is not a class's use: a declaration, an import, or a member's access. */
    private const array NOT_A_CLASS = [
        T_USE,
        T_AS,
        T_CLASS,
        T_INTERFACE,
        T_TRAIT,
        T_ENUM,
        T_FUNCTION,
        T_CONST,
        T_CASE,
        T_DOUBLE_COLON,
        T_OBJECT_OPERATOR,
        T_NULLSAFE_OBJECT_OPERATOR,
    ];

    private function __construct(
        private Path $path,
        private bool $test,
        private Tokens $tokens,
        private Scope $scope,
        private Shape $shape,
    ) {
    }

    public static function read(Path $path, Contents $contents, bool $test): self
    {
        $significant = self::significant($contents);
        $scope = TopLevel::of($significant)->scope();
        $tokens = Tokens::of($significant);

        return new self($path, $test, $tokens, $scope, Shape::of($tokens, $scope));
    }

    public function path(): Path
    {
        return $this->path;
    }

    /** Whether it is a test file, which judges a mutant itself where it reads the mutant's value. */
    public function isTest(): bool
    {
        return $this->test;
    }

    public function tokens(): Tokens
    {
        return $this->tokens;
    }

    public function scope(): Scope
    {
        return $this->scope;
    }

    public function shape(): Shape
    {
        return $this->shape;
    }

    /** What the token at an index stands in. */
    public function symbolAt(int $at): Symbol|Unnamed|Executable
    {
        return SymbolAt::in($this->tokens, $this->shape)->token($at);
    }

    /** The line the token at an index begins on. */
    public function lineOf(int $at): Line
    {
        return Line::of($this->tokens->line($at));
    }

    /** Where the first token a changed copy of the file writes differently stands, or past its last where none does. */
    public function changedAt(Contents $changed): int
    {
        $other = Tokens::of(self::significant($changed));
        $shorter = min($this->tokens->count(), $other->count());

        for ($at = 0; $at < $shorter; $at++) {
            if ($this->tokens->text($at) !== $other->text($at)) {
                return $at;
            }
        }

        return $shorter;
    }

    /** The class-like whose body encloses a token most closely, or none where no class-like does. */
    public function classAround(int $at): ClassLike
    {
        for ($child = $at; $this->tokens->isEnclosed($child); $child = $this->tokens->enclosing($child)) {
            $opener = $this->tokens->enclosing($child);

            if ($this->shape->isClassBody($opener)) {
                return $this->shape->classOf($opener);
            }
        }

        return ClassLike::none();
    }

    /**
     * Where the file writes a name that may stand for a class, other than
     * where it declares or imports it.
     *
     * @return list<int>
     */
    public function namesOf(string $class): array
    {
        $found = [];

        foreach ($this->tokens->indicesOf(...Names::TOKENS) as $at) {
            $named = ! $this->tokens->is($at - 1, ...self::NOT_A_CLASS)
                && $this->scope->resolve($this->tokens->text($at))->meet(Names::of($class));
            $found = $named ? [...$found, $at] : $found;
        }

        return $found;
    }

    /** @return list<PhpToken> */
    private static function significant(Contents $contents): array
    {
        return array_values(array_filter(
            PhpToken::tokenize($contents->text()),
            static fn(PhpToken $token): bool => ! $token->isIgnorable() && ! $token->is(T_CLOSE_TAG),
        ));
    }
}
