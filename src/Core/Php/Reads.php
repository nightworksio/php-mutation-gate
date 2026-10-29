<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_any;
use function array_last;
use function explode;

/** Where one file reads a value, by what kind of value it is. */
final readonly class Reads
{
    /** The name of PHP's function that reads a constant by a name given as a string: the kind's own word. */
    public const string LOOKUP = SymbolKind::Constant->value;

    /**
     * Whether a file may read a value at all, from the words its names end
     * in: a constant's read spells its name, its owner's, a reflection's or
     * `constant`, and an enum case's and an attribute argument's spell their
     * owner's. A file that spells none of them is left unread, which is what
     * every read would find.
     */
    public static function mayHold(Symbol $symbol, Source $source): bool
    {
        $tokens = $source->tokens();

        return match ($symbol->kind()) {
            SymbolKind::Constant => $tokens->namesWord($symbol->name())
                || $tokens->namesWord(self::LOOKUP)
                || array_any(Reflections::CLASSES, $tokens->namesWord(...)),
            SymbolKind::GlobalConstant => $tokens->namesWord($symbol->name())
                || $tokens->namesWord(self::LOOKUP),
            SymbolKind::EnumCase,
            SymbolKind::AttributeArgument => $tokens->namesWord(self::lastSegment($symbol->owner())),
            SymbolKind::StaticProperty,
            SymbolKind::Property,
            SymbolKind::FunctionParameter,
            SymbolKind::MethodParameter,
            SymbolKind::ClosureParameter => true,
        };
    }

    /**
     * Where one file reads a value. An attribute's argument is read where the
     * attribute is made, by reflection that names its owner, or by reflection
     * no name leads to, so its reads are ambiguous.
     */
    public static function of(Symbol $symbol, Source $source, Hierarchy $hierarchy): References
    {
        return match ($symbol->kind()) {
            SymbolKind::Constant, SymbolKind::StaticProperty => StaticReads::of($symbol, $source, $hierarchy),
            SymbolKind::EnumCase => Mentions::of($symbol, $source),
            SymbolKind::GlobalConstant => GlobalReads::of($symbol, $source),
            SymbolKind::Property => Creations::of($symbol, $source, $hierarchy),
            SymbolKind::FunctionParameter => Calls::of($symbol, $source),
            SymbolKind::AttributeArgument => Mentions::of($symbol, $source)->and(References::unknown()),
            SymbolKind::MethodParameter,
            SymbolKind::ClosureParameter => References::unknown(),
        };
    }

    private static function lastSegment(string $name): string
    {
        $segments = explode('\\', $name);

        return array_last($segments);
    }
}
