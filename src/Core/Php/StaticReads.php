<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function count;
use function explode;
use function ltrim;
use function mb_strtolower;
use function sprintf;
use function str_replace;
use function trim;

/**
 * Where one file reads a class constant or a static property: through the
 * owner's name or a name that reaches it, through `self::`, `static::` and
 * `parent::` in a class that reaches it, and, for a constant, through
 * `constant()` with the name written out. A variable class, a `constant()` of
 * a name not written out, reflection on the owner, and `static::` where a
 * subclass declares the constant anew are reads the scan cannot follow.
 */
final readonly class StaticReads
{
    /** The reflection classes that can read a constant by a name the scan cannot see. */
    private const array REFLECTION = [
        'reflectionclass',
        'reflectionclassconstant',
        'reflectionenum',
        'reflectionobject',
    ];

    /** What stands before a `constant` that is not a call of PHP's `constant()`. */
    private const array NOT_A_CALL = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION];

    /** Where the name a `constant()` call reads stands, after its `(`. */
    private const int ARGUMENT = 2;

    /** Where a `constant()` call that reads one string closes. */
    private const int CLOSE = 3;

    private function __construct(private Symbol $symbol, private Source $source, private Hierarchy $hierarchy)
    {
    }

    public static function of(Symbol $symbol, Source $source, Hierarchy $hierarchy): References
    {
        $reads = new self($symbol, $source, $hierarchy);

        return $symbol->kind() === SymbolKind::Constant
            ? $reads->accesses()->and($reads->lookups())->and($reads->reflection())
            : $reads->accesses();
    }

    private function accesses(): References
    {
        $found = References::none();

        foreach ($this->source->tokens()->indicesOf(T_DOUBLE_COLON) as $colon) {
            $found = $this->namesIt($colon + 1) ? $found->and($this->through($colon)) : $found;
        }

        return $found;
    }

    /** Whether the token after a `::` is the symbol's name. */
    private function namesIt(int $at): bool
    {
        $tokens = $this->source->tokens();

        return $this->symbol->kind() === SymbolKind::StaticProperty
            ? $tokens->is($at, T_VARIABLE) && $tokens->text($at) === sprintf('$%s', $this->symbol->name())
            : $tokens->is($at, T_STRING) && $tokens->text($at) === $this->symbol->name();
    }

    /** What the class before a `::` that names the symbol makes of the read. */
    private function through(int $colon): References
    {
        $tokens = $this->source->tokens();
        $left = $colon - 1;
        $said = mb_strtolower($tokens->text($left));
        $around = $this->source->classAround($colon);

        return match (true) {
            $tokens->is($left, T_STATIC) => $this->fromStatic($colon, $around),
            $said === 'self' => $this->reachedBy($around->names(), $colon),
            $said === 'parent' => $this->reachedBy($around->parents(), $colon),
            $tokens->is($left, ...Names::TOKENS) => $this->reachedBy(
                $this->source->scope()->resolve($tokens->text($left)),
                $colon,
            ),
            default => References::unknown(),
        };
    }

    /** A read through `static::`, which a subclass's own constant of the name could answer instead. */
    private function fromStatic(int $colon, ClassLike $around): References
    {
        $read = $this->reachedBy($around->names(), $colon);
        $overridden = $this->symbol->kind() === SymbolKind::Constant
            && $this->hierarchy->overrides($this->symbol->owner(), $this->symbol->name());

        return $read->sites() !== [] && $overridden ? $read->and(References::unknown()) : $read;
    }

    private function reachedBy(Names $names, int $at): References
    {
        return $this->hierarchy->anyReaches($names, $this->symbol->owner(), $this->shadowing())
            ? References::at(Site::in($this->source, $at))
            : References::none();
    }

    /** Every `constant()` call, read where its name is written out and unknown where it is not. */
    private function lookups(): References
    {
        $tokens = $this->source->tokens();
        $found = References::none();

        foreach ($tokens->indicesOf(T_STRING) as $at) {
            $call = mb_strtolower($tokens->text($at)) === 'constant'
                && $tokens->is($at + 1, '(')
                && ! $tokens->is($at - 1, ...self::NOT_A_CALL);
            $found = $call ? $found->and($this->lookedUp($at)) : $found;
        }

        return $found;
    }

    private function lookedUp(int $call): References
    {
        $tokens = $this->source->tokens();

        $argument = $call + self::ARGUMENT;

        if (! $tokens->is($argument, T_CONSTANT_ENCAPSED_STRING) || ! $tokens->is($call + self::CLOSE, ')')) {
            return References::unknown();
        }

        $parts = explode('::', str_replace('\\\\', '\\', trim($tokens->text($argument), '\'"')));
        $reads = count($parts) === 2 && $parts[1] === $this->symbol->name();

        return $reads ? $this->reachedBy(Names::of(ltrim($parts[0], '\\')), $call) : References::none();
    }

    /** Unknown where the file names a reflection class and the owner, which reflection could read by any name. */
    private function reflection(): References
    {
        $reflects = false;

        foreach (self::REFLECTION as $class) {
            $reflects = $reflects || $this->source->namesOf($class) !== [];
        }

        return $reflects && $this->source->namesOf($this->symbol->owner()) !== []
            ? References::unknown()
            : References::none();
    }

    /** The name a class between the reader and the owner would shadow: a constant's, and nothing for a property. */
    private function shadowing(): Shadowing
    {
        return $this->symbol->kind() === SymbolKind::Constant
            ? Shadowing::byConstant($this->symbol->name())
            : Shadowing::none();
    }
}
