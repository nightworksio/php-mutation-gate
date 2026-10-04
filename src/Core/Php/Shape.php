<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_key_exists;
use function array_values;

/**
 * Where a file's declarations stand among its tokens: the body of each class,
 * interface, trait and enum, the parameter list of each function, method and
 * closure, and the body of each of those that has one.
 */
final readonly class Shape
{
    /** What declares a class-like body. */
    private const array CLASS_LIKE = [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM];

    /**
     * @param array<int, ClassLike> $classes    each class-like declaration, by where its body opens
     * @param array<int, Signature> $signatures each parameter list, by where it opens
     * @param array<int, true>      $bodies     where each function's body opens
     */
    private function __construct(private array $classes, private array $signatures, private array $bodies)
    {
    }

    public static function of(Tokens $tokens, Scope $scope): self
    {
        $classes = [];

        foreach ($tokens->indicesOf(...self::CLASS_LIKE) as $at) {
            $body = self::braceAfter($tokens, $at, $at + 1);
            $classes = $tokens->is($at - 1, T_DOUBLE_COLON) || $body === Tokens::NONE
                ? $classes
                : $classes + [$body => ClassLike::at($tokens, $scope, $at, $body)];
        }

        $signatures = [];
        $bodies = [];

        foreach ($tokens->indicesOf(T_FUNCTION, T_FN) as $at) {
            $list = self::parametersAfter($tokens, $at);
            $body = $list === Tokens::NONE || $tokens->is($at, T_FN)
                ? Tokens::NONE
                : self::braceAfter($tokens, $at, $tokens->closing($list) + 1);
            $signatures = $list === Tokens::NONE
                ? $signatures
                : $signatures + [$list => self::signatureAt($tokens, $scope, $at, $classes)];
            $bodies = $body === Tokens::NONE ? $bodies : $bodies + [$body => true];
        }

        return new self($classes, $signatures, $bodies);
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
        return array_key_exists($opener, $this->bodies);
    }

    /** @return list<ClassLike> every class-like the file declares, in order */
    public function classes(): array
    {
        return array_values($this->classes);
    }

    /**
     * Where the first brace from an index opens at a declaring token's own
     * depth, before a `;` there ends its statement, if one does.
     */
    private static function braceAfter(Tokens $tokens, int $declaring, int $from): int
    {
        $depth = $tokens->enclosing($declaring);
        $end = $tokens->isEnclosed($declaring) ? $tokens->closing($depth) : $tokens->count();

        for ($at = $from; $at < $end; $at++) {
            if ($tokens->enclosing($at) === $depth && $tokens->is($at, '{', ';')) {
                return $tokens->is($at, '{') ? $at : Tokens::NONE;
            }
        }

        return Tokens::NONE;
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
