<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_find;
use function array_key_exists;
use function array_last;
use function array_map;
use function in_array;
use function is_int;

use NightWorksIO\MutationGate\Core\Hold\Standing;

use function sprintf;

/**
 * Every run of attribute groups a PHP file writes together, read from its
 * tokens: where each attribute's name stands, and what the run stands on,
 * with the class, method or function it names. The file is never loaded.
 */
final readonly class AttributedMembers
{
    /** What may stand between the attributes and what they stand on. */
    private const array MODIFIERS = [T_STATIC, T_ABSTRACT, T_FINAL, T_READONLY, T_PUBLIC, T_PROTECTED, T_PRIVATE];

    /** What declares a class-like, whose body holds methods. */
    private const array CLASS_LIKE = [T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM];

    /**
     * @param array<int, string|Nameless> $bodies each class-like by where its body opens, fully qualified,
     *                                            or nameless when it is anonymous
     */
    private function __construct(private Tokens $tokens, private Scope $scope, private array $bodies)
    {
    }

    /**
     * Each run of attribute groups, from the first group of the run.
     *
     * @return list<AttributedMember>
     */
    public static function in(Tokens $tokens, Scope $scope): array
    {
        $members = new self($tokens, $scope, self::bodiesIn($tokens, $scope));
        $openers = $tokens->indicesOf(T_ATTRIBUTE);
        $closings = array_map($tokens->closing(...), $openers);
        $found = [];

        foreach ($openers as $at) {
            $found = in_array($at - 1, $closings, strict: true) ? $found : [...$found, $members->runFrom($at)];
        }

        return $found;
    }

    /**
     * Every class, trait, interface and enum a file declares, by where its
     * body opens: the `{` that follows its keyword at the keyword's own depth
     * before any other such keyword does.
     *
     * @return array<int, string|Nameless>
     */
    private static function bodiesIn(Tokens $tokens, Scope $scope): array
    {
        $bodies = [];

        foreach ($tokens->indicesOf(...self::CLASS_LIKE) as $at) {
            $body = array_find(
                $tokens->inside($tokens->enclosing($at), '{', ...self::CLASS_LIKE),
                static fn(int $next): bool => $next > $at,
            );
            $name = $tokens->is($at + 1, T_STRING) ? $scope->declared($tokens->text($at + 1)) : Nameless::code();
            $bodies = is_int($body) && $tokens->is($body, '{') && ! $tokens->is($at - 1, T_DOUBLE_COLON)
                ? $bodies + [$body => $name]
                : $bodies;
        }

        return $bodies;
    }

    /** The run of attribute groups written together from an index, and what it stands on. */
    private function runFrom(int $start): AttributedMember
    {
        $groups = [$start];

        while ($this->tokens->is($this->tokens->closing(array_last($groups)) + 1, T_ATTRIBUTE)) {
            $groups[] = $this->tokens->closing(array_last($groups)) + 1;
        }

        $names = [];

        foreach ($groups as $group) {
            $names = [...$names, ...$this->tokens->inside($group, ...Names::TOKENS)];
        }

        [$standing, $holder] = $this->standingAfter($this->tokens->closing(array_last($groups)) + 1, $start);

        return AttributedMember::of($names, $standing, $holder);
    }

    /**
     * What the attributes that begin at one index and end before another stand
     * on, with the class, method or function it names.
     *
     * @return array{Standing, string}
     */
    private function standingAfter(int $end, int $start): array
    {
        $at = $end;

        while ($this->tokens->is($at, ...self::MODIFIERS)) {
            $at++;
        }

        return match (true) {
            $this->tokens->is($at, T_FUNCTION) => $this->functionAt($at, $start),
            $this->tokens->is($at, T_FN) => [ClosureCall::standingOf($this->tokens, $start), ''],
            $this->tokens->is($at, T_CLASS) && $this->tokens->is($at + 1, T_STRING) => [
                Standing::TestClass,
                $this->scope->declared($this->tokens->text($at + 1)),
            ],
            default => [Standing::Elsewhere, ''],
        };
    }

    /**
     * What the `function` at an index is: a closure, a method of a class, or
     * a function declared with a name.
     *
     * @return array{Standing, string}
     */
    private function functionAt(int $at, int $start): array
    {
        $name = $this->tokens->functionName($at);

        if ($name === Tokens::NONE) {
            return [ClosureCall::standingOf($this->tokens, $start), ''];
        }

        $body = $this->tokens->enclosing($start);

        return match (true) {
            ! array_key_exists($body, $this->bodies) => [
                Standing::NamedFunction,
                $this->scope->declared($this->tokens->text($name)),
            ],
            $this->bodies[$body] instanceof Nameless => [Standing::Elsewhere, ''],
            default => [Standing::TestMethod, sprintf('%s::%s', $this->bodies[$body], $this->tokens->text($name))],
        };
    }
}
