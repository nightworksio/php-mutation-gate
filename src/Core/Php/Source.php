<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_diff_assoc;
use function array_filter;
use function array_keys;
use function array_values;
use function count;
use function min;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use PhpToken;

/**
 * A PHP file of the project, read by its tokens for what reads a value it or
 * another file declares: a test, which judges a mutant when it reads it, or a
 * source file, whose line that reads it is judged by the tests that cover it.
 *
 * @internal
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

    /** A file of the code the tests judge, read. */
    public static function ofCode(Path $path, Contents $contents): self
    {
        return self::read($path, $contents, test: false);
    }

    /** A test file, read. */
    public static function ofTest(Path $path, Contents $contents): self
    {
        return self::read($path, $contents, test: true);
    }

    /** The scope a file's code ends in: the namespace it declares last, and what that imports. */
    public static function scopeOf(Contents $contents): Scope
    {
        return TopLevel::of(self::significant($contents))->scope();
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
        return SymbolAt::in($this->tokens, $this->shape, $this->scope)->token($at);
    }

    /**
     * The site of a read in a parameter's default, run by the body of the
     * function the default is for; none where the token stands in no default,
     * or the function has no body, as an abstract method has none.
     */
    public function defaultRunBy(int $at): Site|NotGiven
    {
        for ($child = $at; $this->tokens->isEnclosed($child); $child = $this->tokens->enclosing($child)) {
            $opener = $this->tokens->enclosing($child);

            if ($this->shape->isSignature($opener)) {
                return $this->bodyRunning($at, $this->shape->bodyAfter($opener));
            }

            if ($this->shape->isBody($opener) || $this->shape->isClassBody($opener)) {
                return NotGiven::value();
            }
        }

        return NotGiven::value();
    }

    /** The line the token at an index begins on. */
    public function lineOf(int $at): Line
    {
        return Line::of($this->tokens->line($at));
    }

    /**
     * Where the first token a change to a print of the file writes
     * differently stands in the file: where the print and the changed print
     * first differ, carried back through the tokens the file and its print
     * share, since a print lays the code out its own way. Past the file's
     * last token where the two prints agree.
     */
    public function changedAt(Contents $print, Contents $changed): int
    {
        $printed = Tokens::of(self::significant($print))->texts();
        $other = Tokens::of(self::significant($changed))->texts();
        $differ = array_keys(array_diff_assoc($printed, $other));
        $at = $differ === [] ? count($printed) : min($differ);

        return TokenAlignment::of($this->tokens->texts(), $printed)->inFirst($at);
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
        return Identifiers::of(array_values(array_filter(
            PhpToken::tokenize($contents->text()),
            static fn(PhpToken $token): bool => ! $token->isIgnorable() && ! $token->is(T_CLOSE_TAG),
        )));
    }

    private function bodyRunning(int $at, int $body): Site|NotGiven
    {
        return $body === Tokens::NONE
            ? NotGiven::value()
            : Site::runBy($this, $at, $this->lineOf($body), $this->lineOf($this->tokens->closing($body)));
    }
}
