<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_key_exists;
use function mb_strtolower;
use function mb_substr;
use function sprintf;
use function str_ends_with;

/**
 * A PhpSpec spec, read from its file's tokens: a class that extends
 * PhpSpec's `ObjectBehavior`, by that name however it is qualified, and the
 * matchers of its own its `getMatchers()` names by their keys.
 */
final readonly class Spec
{
    /** How far past the `class` keyword `extends` stands, after the class's name. */
    private const int EXTENDS = 2;

    /** The class a spec extends, which PhpSpec runs its examples on. */
    private const string BEHAVIOUR = 'ObjectBehavior';

    /** The method a spec names its own matchers in, each by its key. */
    private const string MATCHERS = 'getmatchers';

    /** @param array<string, true> $matchers each matcher of its own, by its name in lower case */
    private function __construct(private array $matchers)
    {
    }

    /** The spec the first named class of some tokens is; none where it extends no `ObjectBehavior`. */
    public static function in(Tokens $tokens): self|Nameless
    {
        foreach ($tokens->indicesOf(T_CLASS) as $class) {
            if ($tokens->is($class + 1, T_STRING)) {
                return self::extendedAt($tokens, $class) ? new self(self::matchersIn($tokens)) : Nameless::code();
            }
        }

        return Nameless::code();
    }

    /** Whether the class a `class` keyword at an index declares extends `ObjectBehavior`, by its name. */
    public static function extendedAt(Tokens $tokens, int $class): bool
    {
        $extends = $class + self::EXTENDS;

        return $tokens->is($extends, T_EXTENDS)
            && $tokens->is($extends + 1, ...Names::UNRELATIVE)
            && str_ends_with(sprintf('\\%s', $tokens->text($extends + 1)), sprintf('\\%s', self::BEHAVIOUR));
    }

    /** Whether it names a matcher of its own by this name, in any case. */
    public function hasMatcher(string $name): bool
    {
        return array_key_exists(mb_strtolower($name), $this->matchers);
    }

    /**
     * The keys of what `getMatchers()` returns: each string in its body
     * before a `=>`; none where the tokens declare no `getMatchers()`.
     *
     * @return array<string, true>
     */
    private static function matchersIn(Tokens $tokens): array
    {
        $body = self::matchersBody($tokens);
        $ends = $body === Tokens::NONE ? Tokens::NONE : $tokens->closing($body);
        $matchers = [];

        foreach ($tokens->indicesOf(T_CONSTANT_ENCAPSED_STRING) as $at) {
            $keyed = $at > $body && $at < $ends && $tokens->is($at + 1, T_DOUBLE_ARROW);
            $matchers = $keyed ? [...$matchers, self::unquoted($tokens->text($at)) => true] : $matchers;
        }

        return $matchers;
    }

    /** Where the body of the first `getMatchers()` the tokens declare opens; none where they declare none. */
    private static function matchersBody(Tokens $tokens): int
    {
        foreach ($tokens->indicesOf(T_FUNCTION) as $function) {
            $named = $tokens->functionName($function);

            if ($named !== Tokens::NONE && mb_strtolower($tokens->text($named)) === self::MATCHERS) {
                return Body::after($tokens, $named);
            }
        }

        return Tokens::NONE;
    }

    /** A string literal's text, its quotes taken off, in lower case. */
    private static function unquoted(string $literal): string
    {
        return mb_strtolower(mb_substr($literal, 1, -1));
    }
}
