<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function ltrim;
use function mb_strtolower;
use function sprintf;

/**
 * What a line that is not executable declares: a class constant, an enum case,
 * a property or a parameter, with the class or function it belongs to and its
 * name as written. The owner is fully qualified; an anonymous class or a
 * closure owns nothing a reference can name.
 */
final readonly class Symbol
{
    private function __construct(private SymbolKind $kind, private string $owner, private string $name)
    {
    }

    /** A constant of a class, an interface, a trait or an enum. */
    public static function constant(string $owner, string $name): self
    {
        return new self(SymbolKind::Constant, $owner, $name);
    }

    public static function enumCase(string $owner, string $name): self
    {
        return new self(SymbolKind::EnumCase, $owner, $name);
    }

    /** A property's default, static or not, by its name without the `$`. */
    public static function property(string $owner, string $name, bool $static): self
    {
        return new self($static ? SymbolKind::StaticProperty : SymbolKind::Property, $owner, $name);
    }

    /** A plain function's parameter default, by the function's fully qualified name. */
    public static function parameter(string $function, string $name): self
    {
        return new self(SymbolKind::FunctionParameter, $function, $name);
    }

    /** A value no reference names: a method's or a closure's parameter default, or an attribute's argument. */
    public static function unnamed(SymbolKind $kind): self
    {
        return new self($kind, '', '');
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

    /** Whether references to it can be found: of a kind a scan follows, and owned by something named. */
    public function isFollowable(): bool
    {
        return $this->kind->isFollowable() && $this->owner !== '';
    }

    /** How a reason names it, such as `constant Money::RATE`. */
    public function described(): string
    {
        return $this->owner === ''
            ? $this->kind->value
            : sprintf('%s %s::%s', $this->kind->value, $this->owner, $this->name);
    }
}
