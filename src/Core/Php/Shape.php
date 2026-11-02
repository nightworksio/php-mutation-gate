<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_flip;
use function array_key_exists;
use function array_values;

/**
 * Where a file's declarations stand among its tokens: the body of each class,
 * interface, trait and enum, the parameter list of each function, method and
 * closure, and the body of each of those that has one.
 *
 * @internal
 */
final readonly class Shape
{
    /**
     * @param array<int, ClassLike> $classes    each class-like declaration, by where its body opens
     * @param array<int, Signature> $signatures each parameter list, by where it opens
     * @param array<int, int>       $bodies     where each function's body opens, by where its `function` stands
     * @param array<int, int>       $opened     where each function's `function` stands, by where its body opens
     * @param array<int, int>       $after      each function's body or nowhere, by where its parameters open
     */
    private function __construct(
        private array $classes,
        private array $signatures,
        private array $bodies,
        private array $opened,
        private array $after,
    ) {
    }

    public static function of(Tokens $tokens, Scope $scope): self
    {
        $classes = self::classesIn($tokens, $scope);
        $signatures = [];
        $bodies = [];
        $after = [];

        foreach ($tokens->indicesOf(T_FUNCTION, T_FN) as $at) {
            $list = self::parametersAfter($tokens, $at);

            if ($list === Tokens::NONE) {
                continue;
            }

            $body = $tokens->is($at, T_FN) ? Tokens::NONE : self::braceAfter($tokens, $at);
            $signatures += [$list => self::signatureAt($tokens, $scope, $at, $classes)];
            $after += [$list => $body];
            $bodies = $body === Tokens::NONE ? $bodies : $bodies + [$at => $body];
        }

        return new self($classes, $signatures, $bodies, array_flip($bodies), $after);
    }

    /** Whether a class-like body opens at an index. */
    public function isClassBody(int $opener): bool
    {
        return array_key_exists($opener, $this->classes);
    }

    /** The class-like whose body opens at an index, which must be one. */
    public function classOf(int $opener): ClassLike
    {
        return $this->classes[$opener];
    }

    /** Whether a parameter list opens at an index. */
    public function isSignature(int $opener): bool
    {
        return array_key_exists($opener, $this->signatures);
    }

    /** The parameter list that opens at an index, which must be one. */
    public function signatureOf(int $opener): Signature
    {
        return $this->signatures[$opener];
    }

    /** Whether a function's, a method's or a closure's body opens at an index. */
    public function isBody(int $opener): bool
    {
        return array_key_exists($opener, $this->opened);
    }

    /** Where the body of the function whose `function` stands at an index opens; nowhere where it has none. */
    public function bodyOf(int $keyword): int
    {
        return array_key_exists($keyword, $this->bodies) ? $this->bodies[$keyword] : Tokens::NONE;
    }

    /**
     * Where the body of the function whose parameter list opens at an index,
     * which must be one, opens; nowhere where it has none.
     */
    public function bodyAfter(int $list): int
    {
        return $this->after[$list];
    }

    /**
     * Where the first brace after a declaring token opens at the token's own
     * depth, before a `;` there ends its statement; nowhere where none does.
     */
    public static function braceAfter(Tokens $tokens, int $declaring): int
    {
        $depth = $tokens->enclosing($declaring);

        foreach ($tokens->between($declaring, $tokens->count()) as $at => $token) {
            if ($tokens->enclosing($at) === $depth && $token->is(['{', ';'])) {
                return $token->is('{') ? $at : Tokens::NONE;
            }
        }

        return Tokens::NONE;
    }

    /** @return list<ClassLike> every class-like the file declares, in order */
    public function classes(): array
    {
        return array_values($this->classes);
    }

    /** @return array<int, ClassLike> each class-like declaration, by where its body opens */
    private static function classesIn(Tokens $tokens, Scope $scope): array
    {
        $classes = [];

        foreach ($tokens->indicesOf(...Tokens::CLASS_LIKE) as $at) {
            $body = self::braceAfter($tokens, $at);
            $classes = $tokens->is($at - 1, T_DOUBLE_COLON) || $body === Tokens::NONE
                ? $classes
                : $classes + [$body => ClassLike::at($tokens, $scope, $at, $body)];
        }

        return $classes;
    }

    /** Where the parameter list after `function` or `fn` opens, past a `&` and a name, if one does. */
    private static function parametersAfter(Tokens $tokens, int $at): int
    {
        $name = $tokens->functionName($at);
        $list = $name === Tokens::NONE ? $at + 1 + ($tokens->is($at + 1, '&') ? 1 : 0) : $name + 1;

        return $tokens->is($list, '(') ? $list : Tokens::NONE;
    }

    /** @param array<int, ClassLike> $classes */
    private static function signatureAt(Tokens $tokens, Scope $scope, int $at, array $classes): Signature
    {
        $name = $tokens->functionName($at);

        return match (true) {
            $tokens->is($at, T_FN) || $name === Tokens::NONE => Signature::ofClosure(),
            array_key_exists($tokens->enclosing($at), $classes) => Signature::ofMethod(),
            default => Signature::ofFunction($scope->declared($tokens->text($name))),
        };
    }
}
