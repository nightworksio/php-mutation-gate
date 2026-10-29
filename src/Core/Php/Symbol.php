<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_last;
use function explode;
use function ltrim;
use function mb_strtolower;
use function sprintf;

/**
 * What a line that is not executable declares: a class constant, an enum case,
 * a property, a plain function's parameter or an attribute's argument, with
 * the class or function it belongs to and its name as written. The owner is
 * fully qualified; one an anonymous class owns is unnamed, since no reference
 * can name it.
 */
final readonly class Symbol
{
    private function __construct(private SymbolKind $kind, private string $owner, private string $name)
    {
    }

    /**
     * A constant of a class, an interface, a trait or an enum.
     *
     * @return ($owner is string ? self : Unnamed)
     */
    public static function constant(string|Nameless $owner, string $name): self|Unnamed
    {
        return self::owned(SymbolKind::Constant, $owner, $name);
    }

    /** @return ($owner is string ? self : Unnamed) */
    public static function enumCase(string|Nameless $owner, string $name): self|Unnamed
    {
        return self::owned(SymbolKind::EnumCase, $owner, $name);
    }

    /**
     * A property's default, static or not, by its name without the `$`.
     *
     * @return ($owner is string ? self : Unnamed)
     */
    public static function property(string|Nameless $owner, string $name, bool $static): self|Unnamed
    {
        return self::owned($static ? SymbolKind::StaticProperty : SymbolKind::Property, $owner, $name);
    }

    /**
     * A constant `const` declares outside every class, or `define()` does, by
     * its fully qualified name; its owner is that name, and its name is the
     * last segment of it.
     */
    public static function globalConstant(string $name): self
    {
        $owner = ltrim($name, '\\');

        return new self(SymbolKind::GlobalConstant, $owner, array_last(explode('\\', $owner)));
    }

    /**
     * An attribute's argument, by the class-like that owns it: the
     * attribute's class, or the class-like `#[Attribute]` makes an attribute;
     * and the attribute's name as written.
     *
     * @return ($owner is string ? self : Unnamed)
     */
    public static function attributeArgument(string|Nameless $owner, string $attribute): self|Unnamed
    {
        return self::owned(SymbolKind::AttributeArgument, $owner, $attribute);
    }

    /** A plain function's parameter default, by the function's fully qualified name. */
    public static function parameter(string $function, string $name): self
    {
        return new self(SymbolKind::FunctionParameter, $function, $name);
    }

    public function kind(): SymbolKind
    {
        return $this->kind;
    }

    /** The class or function it belongs to, fully qualified, in lower case, since PHP compares them so. */
    public function owner(): string
    {
        return mb_strtolower(ltrim($this->owner, '\\'));
    }

    /** Its name as declared: a constant's, a case's, or a property's or parameter's without the `$`. */
    public function name(): string
    {
        return $this->name;
    }

    /** How a reason names it, such as `constant Money::RATE` or `global constant App\LIMIT`. */
    public function described(): string
    {
        return $this->kind === SymbolKind::GlobalConstant
            ? sprintf('%s %s', $this->kind->value, $this->owner)
            : sprintf('%s %s::%s', $this->kind->value, $this->owner, $this->name);
    }

    /** @return ($owner is string ? self : Unnamed) */
    private static function owned(SymbolKind $kind, string|Nameless $owner, string $name): self|Unnamed
    {
        return $owner instanceof Nameless ? Unnamed::of($kind) : new self($kind, $owner, $name);
    }
}
