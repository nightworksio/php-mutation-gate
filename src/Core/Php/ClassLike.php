<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function in_array;
use function mb_strtolower;

/**
 * A class, interface, trait or enum a file declares: its fully qualified name,
 * what it may extend or implement, and the constants it declares. An anonymous
 * class has no name.
 */
final readonly class ClassLike
{
    /** What introduces the names a class extends or implements. */
    private const array PARENTS = [T_EXTENDS, T_IMPLEMENTS];

    /** What a member's statement directly inside a class-like body begins with or follows. */
    private const array STARTS = [T_CONST, T_CASE, T_VARIABLE, ';', '{'];

    /** @param list<string> $constants */
    private function __construct(private string $name, private Names $parents, private array $constants)
    {
    }

    /**
     * The class-like a `class`, `interface`, `trait` or `enum` keyword
     * declares, whose body opens at an index: its name, its parents, and the
     * constants its body declares.
     */
    public static function at(Tokens $tokens, Scope $scope, int $keyword, int $body): self
    {
        $parents = Names::of();
        $naming = false;

        for ($each = $keyword + 1; $each < $body; $each++) {
            $naming = $naming || $tokens->is($each, ...self::PARENTS);
            $parents = $naming && $tokens->is($each, ...Source::NAMES)
                ? $parents->merge($scope->resolve($tokens->text($each)))
                : $parents;
        }

        $name = $tokens->is($keyword + 1, T_STRING) ? $scope->declared($tokens->text($keyword + 1)) : '';
        $constants = [];

        foreach ($tokens->inside($body, '=') as $equals) {
            $constants = self::isConstant($tokens, $body, $equals)
                ? [...$constants, $tokens->text($equals - 1)]
                : $constants;
        }

        return new self($name, $parents, $constants);
    }

    /** A place no class-like encloses, which names nothing and extends nothing. */
    public static function none(): self
    {
        return new self('', Names::of(), []);
    }

    /** Its fully qualified name as declared, or nothing for an anonymous class. */
    public function name(): string
    {
        return $this->name;
    }

    /** Its fully qualified name in lower case, as PHP compares class names. */
    public function key(): string
    {
        return mb_strtolower($this->name);
    }

    /** What its `extends` and `implements` may name, each in lower case. */
    public function parents(): Names
    {
        return $this->parents;
    }

    public function declares(string $constant): bool
    {
        return in_array($constant, $this->constants, strict: true);
    }

    /** Whether an `=` directly inside a class-like body gives a constant its value. */
    private static function isConstant(Tokens $tokens, int $body, int $equals): bool
    {
        $at = $equals - 1;

        while ($at > $body && ($tokens->enclosing($at) !== $body || !$tokens->is($at, ...self::STARTS))) {
            $at--;
        }

        return $tokens->is($at, T_CONST);
    }
}
