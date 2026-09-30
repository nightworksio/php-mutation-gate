<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

/** Where one file reads a value, by what kind of value it is. */
final readonly class Reads
{
    public static function of(Symbol $symbol, Source $source, Hierarchy $hierarchy): References
    {
        return match ($symbol->kind()) {
            SymbolKind::Constant, SymbolKind::StaticProperty => StaticReads::of($symbol, $source, $hierarchy),
            SymbolKind::EnumCase => Mentions::of($symbol, $source),
            SymbolKind::Property => Creations::of($symbol, $source, $hierarchy),
            SymbolKind::FunctionParameter => Calls::of($symbol, $source),
            SymbolKind::MethodParameter,
            SymbolKind::ClosureParameter,
            SymbolKind::AttributeArgument => References::unknown(),
        };
    }
}
