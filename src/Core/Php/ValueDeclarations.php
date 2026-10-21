<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_push;

use Closure;

use function ltrim;
use function mb_strtolower;

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * The values a file declares by a name a scan can follow: each constant and
 * each backed case of a named class-like, and each global constant `const`
 * or `define()` with its name written out declares. A constant of an
 * anonymous class has no name a read could spell, and a `case` outside a
 * class-like belongs to a `switch`.
 */
final readonly class ValueDeclarations
{
    /** PHP's function that declares a global constant at run time. */
    private const string DEFINE = 'define';

    private function __construct(private Source $source)
    {
    }

    /** @return list<ValueDeclaration> */
    public static function in(Source $source): array
    {
        $declarations = new self($source);
        $found = [];

        foreach ($source->tokens()->indicesOf(T_CONST, T_CASE) as $keyword) {
            array_push($found, ...$declarations->declaredAt($keyword));
        }

        foreach ($source->tokens()->indicesOf(T_STRING, T_NAME_FULLY_QUALIFIED) as $name) {
            array_push($found, ...$declarations->defined($name));
        }

        return $found;
    }

    /**
     * What a `const` or a `case` declares: each constant it names, a backed
     * case, or nothing for an unbacked case, a `switch` case or a constant
     * of an anonymous class.
     *
     * @return list<ValueDeclaration>
     */
    private function declaredAt(int $keyword): array
    {
        $tokens = $this->source->tokens();
        $opener = $tokens->isEnclosed($keyword) ? $tokens->enclosing($keyword) : Tokens::NONE;
        $inClass = $opener !== Tokens::NONE && $this->source->shape()->isClassBody($opener);
        $owner = $inClass ? $this->source->shape()->classOf($opener)->name() : '';

        return match (true) {
            $tokens->is($keyword, T_CASE) => $inClass ? $this->backed($keyword, $opener, $owner) : [],
            $inClass => $this->constants(
                $keyword,
                $opener,
                static fn(string $name): Symbol|Unnamed => Symbol::constant($owner, $name),
            ),
            default => $this->constants(
                $keyword,
                $opener,
                fn(string $name): Symbol => Symbol::globalConstant($this->source->scope()->declared($name)),
            ),
        };
    }

    /**
     * A backed enum case, from its name to the end of its value.
     *
     * @return list<ValueDeclaration>
     */
    private function backed(int $keyword, int $opener, string|Nameless $owner): array
    {
        $tokens = $this->source->tokens();
        $name = $keyword + 1;
        $symbol = Symbol::enumCase($owner, $tokens->text($name));

        return $tokens->is($name + 1, '=') && $symbol instanceof Symbol
            ? [$this->spanning($symbol, $name, $this->endOfValue($name + 1, $opener))]
            : [];
    }

    /**
     * Each constant a `const` statement names, from its name to the end of
     * its value.
     *
     * @param  Closure(string): (Symbol|Unnamed) $symbolOf
     * @return list<ValueDeclaration>
     */
    private function constants(int $keyword, int $opener, Closure $symbolOf): array
    {
        $tokens = $this->source->tokens();
        $found = [];

        for ($at = $keyword + 1; $at < $tokens->count() && ! $this->ends($at, $opener, ';'); ++$at) {
            $symbol = $this->ends($at, $opener, '=') ? $symbolOf($tokens->text($at - 1)) : NotGiven::value();

            if ($symbol instanceof Symbol) {
                $found[] = $this->spanning($symbol, $at - 1, $this->endOfValue($at, $opener));
            }
        }

        return $found;
    }

    /**
     * What a `define()` call with its name written out declares; nothing for
     * any other token.
     *
     * @return list<ValueDeclaration>
     */
    private function defined(int $name): array
    {
        $tokens = $this->source->tokens();
        $argument = $name + Tokens::ARGUMENT;
        $call = mb_strtolower(ltrim($tokens->text($name), '\\')) === self::DEFINE
            && $tokens->is($name + 1, '(')
            && ! $tokens->is($name - 1, ...Calls::NOT_A_CALL)
            && $tokens->is($argument, T_CONSTANT_ENCAPSED_STRING)
            && $tokens->is($name + StaticReads::CLOSE, ',');

        if (! $call) {
            return [];
        }

        $symbol = Symbol::globalConstant($tokens->unquoted($argument));

        return [$this->spanning($symbol, $name, $tokens->closing($name + 1))];
    }

    /**
     * The last token of a value that begins after a token, directly inside a
     * bracket: the one before the next `,` or `;` there.
     */
    private function endOfValue(int $from, int $opener): int
    {
        $tokens = $this->source->tokens();
        $at = $from + 1;

        while ($at < $tokens->count() && ! $this->ends($at, $opener, ',') && ! $this->ends($at, $opener, ';')) {
            ++$at;
        }

        return $at - 1;
    }

    /** Whether a token of a kind stands at an index directly inside the bracket that opens at another. */
    private function ends(int $at, int $opener, string $kind): bool
    {
        $tokens = $this->source->tokens();
        $enclosing = $tokens->isEnclosed($at) ? $tokens->enclosing($at) : Tokens::NONE;

        return $tokens->is($at, $kind) && $enclosing === $opener;
    }

    private function spanning(Symbol $symbol, int $from, int $to): ValueDeclaration
    {
        return ValueDeclaration::of(
            $symbol,
            Line::of($this->source->tokens()->line($from)),
            Line::of($this->source->tokens()->line($to)),
        );
    }
}
