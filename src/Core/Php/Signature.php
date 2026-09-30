<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

/**
 * A function's parameter list: a plain function's, which calls name, or a
 * method's or a closure's, whose calls a token scan cannot tell apart.
 */
final readonly class Signature
{
    private function __construct(private SymbolKind $kind, private string $function)
    {
    }

    /** A plain function's, by its fully qualified name. */
    public static function ofFunction(string $function): self
    {
        return new self(SymbolKind::FunctionParameter, $function);
    }

    public static function ofMethod(): self
    {
        return new self(SymbolKind::MethodParameter, '');
    }

    public static function ofClosure(): self
    {
        return new self(SymbolKind::ClosureParameter, '');
    }

    /** What the default of one of its parameters is, by the parameter's name without the `$`. */
    public function defaultOf(string $parameter): Symbol
    {
        return $this->kind === SymbolKind::FunctionParameter
            ? Symbol::parameter($this->function, $parameter)
            : Symbol::unnamed($this->kind);
    }
}
