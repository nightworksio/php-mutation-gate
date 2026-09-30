<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function ltrim;
use function mb_strtolower;
use function sprintf;

/**
 * What a line that is not executable declares: a class constant, an enum case,
 * a property or a plain function's parameter, with the class or function it
 * belongs to and its name as written. The owner is fully qualified; one an
 * anonymous class owns is unnamed, since no reference can name it.
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

    /** How a reason names it, such as `constant Money::RATE`. */
    public function described(): string
    {
        return sprintf('%s %s::%s', $this->kind->value, $this->owner, $this->name);
    }

    /** @return ($owner is string ? self : Unnamed) */
    private static function owned(SymbolKind $kind, string|Nameless $owner, string $name): self|Unnamed
    {
        return $owner instanceof Nameless ? Unnamed::of($kind) : new self($kind, $owner, $name);
    }
}
