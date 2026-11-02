<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

/**
 * A value no reference names, so none can be followed to what reads it: a
 * method's or a closure's parameter default, a member of an anonymous class,
 * and an argument of `#[Attribute]` that stands on no named class-like.
 *
 * @internal
 */
final readonly class Unnamed
{
    private function __construct(private SymbolKind $kind)
    {
    }

    public static function of(SymbolKind $kind): self
    {
        return new self($kind);
    }

    public function kind(): SymbolKind
    {
        return $this->kind;
    }

    /** How a reason names it, such as `closure parameter`. */
    public function described(): string
    {
        return $this->kind->value;
    }
}
