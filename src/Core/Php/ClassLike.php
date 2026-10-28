<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function in_array;
use function mb_strtolower;
use function range;

/**
 * A class, interface, trait or enum a file declares: its fully qualified name,
 * what it may extend or implement, the traits it uses, and the constants it
 * declares. An anonymous class has no name.
 */
final readonly class ClassLike
{
    /** What introduces the names a class extends or implements. */
    private const array PARENTS = [T_EXTENDS, T_IMPLEMENTS];

    /**
     * What a class-like body's members are read by, directly inside it: the
     * `const` that opens a declaration of constants, the `=` that gives each
     * a value, and the `;` that ends a member.
     */
    private const array MEMBERS = [T_CONST, '=', ';'];

    /**
     * What a class-like body's trait uses are read by, directly inside it:
     * the `use` that opens one, the names it holds, and the `;` or the `{`
     * of its adaptations that ends it.
     */
    private const array USES = [T_USE, ';', '{', ...Names::TOKENS];

    /** What ends a trait use. */
    private const array USE_ENDS = [';', '{'];

    /** @param list<string> $constants */
    private function __construct(
        private string|Nameless $name,
        private Names $parents,
        private Names $uses,
        private array $constants,
        private bool $trait,
    ) {
    }

    /**
     * The class-like a `class`, `interface`, `trait` or `enum` keyword
     * declares, whose body opens at an index: its name, its parents, the
     * traits its body uses, and the constants its body declares.
     */
    public static function at(Tokens $tokens, Scope $scope, int $keyword, int $body): self
    {
        $name = $tokens->is($keyword + 1, T_STRING) ? $scope->declared($tokens->text($keyword + 1)) : Nameless::code();

        return new self(
            $name,
            self::parentsOf($tokens, $scope, $keyword, $body),
            self::usesIn($tokens, $scope, $body),
            self::constantsIn($tokens, $body),
            trait: $tokens->is($keyword, T_TRAIT),
        );
    }

    /** A place no class-like encloses, which names nothing and extends nothing. */
    public static function none(): self
    {
        return new self(Nameless::code(), Names::of(), Names::of(), [], trait: false);
    }

    /** Its fully qualified name as declared; an anonymous class has none. */
    public function name(): string|Nameless
    {
        return $this->name;
    }

    /** Its fully qualified name in lower case, as PHP compares class names; an anonymous class has none. */
    public function key(): string|Nameless
    {
        return $this->name instanceof Nameless ? $this->name : mb_strtolower($this->name);
    }

    /** The names `self` and `static` may stand for in its body: its own, or none in an anonymous class. */
    public function names(): Names
    {
        $key = $this->key();

        return $key instanceof Nameless ? Names::of() : Names::of($key);
    }

    /** What its `extends` and `implements` may name, each in lower case. */
    public function parents(): Names
    {
        return $this->parents;
    }

    /** The traits its body uses, each fully qualified. */
    public function uses(): Names
    {
        return $this->uses;
    }

    /**
     * Whether it is a trait, whose methods a class that uses it may override:
     * `$this`, `self` and `static` in its body stand for that class.
     */
    public function isTrait(): bool
    {
        return $this->trait;
    }

    public function declares(string $constant): bool
    {
        return in_array($constant, $this->constants, strict: true);
    }

    /** What the names between a class-like's keyword and its body extend or implement, each resolved. */
    private static function parentsOf(Tokens $tokens, Scope $scope, int $keyword, int $body): Names
    {
        $parents = Names::of();
        $naming = false;

        foreach (range($keyword, $body) as $each) {
            $naming = $naming || $tokens->is($each, ...self::PARENTS);
            $parents = $naming && $tokens->is($each, ...Names::TOKENS)
                ? $parents->merge($scope->resolve($tokens->text($each)))
                : $parents;
        }

        return $parents;
    }

    /** The traits each `use` directly inside a class-like body names, each resolved as a class. */
    private static function usesIn(Tokens $tokens, Scope $scope, int $body): Names
    {
        $uses = Names::of();
        $using = false;

        foreach ($tokens->inside($body, ...self::USES) as $at) {
            $using = $tokens->is($at, T_USE) || ($using && ! $tokens->is($at, ...self::USE_ENDS));
            $uses = $using && $tokens->is($at, ...Names::TOKENS)
                ? $uses->merge(Names::of($scope->className($tokens->text($at))))
                : $uses;
        }

        return $uses;
    }

    /**
     * The constants a class-like body declares: the name before each `=` of a
     * member a `const` opens, until the `;` that ends it.
     *
     * @return list<string>
     */
    private static function constantsIn(Tokens $tokens, int $body): array
    {
        $constants = [];
        $declaring = false;

        foreach ($tokens->inside($body, ...self::MEMBERS) as $at) {
            $declaring = $tokens->is($at, T_CONST) || ($declaring && ! $tokens->is($at, ';'));
            $constants = $declaring && $tokens->is($at, '=') ? [...$constants, $tokens->text($at - 1)] : $constants;
        }

        return $constants;
    }
}
