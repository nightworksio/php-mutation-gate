<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_combine;
use function array_flip;
use function array_keys;
use function array_map;
use function array_push;
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

    /** The tokens that mark where a `const` or `case` statement and each value it gives start and end. */
    private const array MARKS = [T_CONST, T_CASE, '=', ',', ';'];

    /** The marks a `const` or `case` statement goes on past. */
    private const array WITHIN = [T_CONST, T_CASE, '=', ','];

    /** @param array<int, list<int>> $marks where each mark stands in a bracket a `const` or `case` is in, by it */
    private function __construct(private Source $source, private array $marks)
    {
    }

    /** @return list<ValueDeclaration> */
    public static function in(Source $source): array
    {
        $tokens = $source->tokens();
        $keywords = $tokens->indicesOf(T_CONST, T_CASE);
        $openers = array_keys(array_flip(array_map($tokens->enclosing(...), $keywords)));
        $marks = array_map(
            static fn(int $opener): array => [...$tokens->inside($opener, ...self::MARKS), $tokens->count()],
            $openers,
        );
        $declarations = new self($source, array_combine($openers, $marks));
        $found = [];

        foreach ($keywords as $keyword) {
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
        $found = [];

        foreach ($this->valuesAt($keyword) as [$name, $last]) {
            $symbol = $this->symbolOf($keyword, $this->source->tokens()->text($name));

            if ($symbol instanceof Symbol) {
                $found[] = $this->spanning($symbol, $name, $last);
            }
        }

        return $found;
    }

    /**
     * Where each value a `const` or a `case` gives stands: the name it gives
     * the value, and the last token of the value, the one before the `,` or
     * `;` after it, or the file's last where none ends it.
     *
     * @return list<array{int, int}>
     */
    private function valuesAt(int $keyword): array
    {
        $tokens = $this->source->tokens();
        $marks = $this->marks[$tokens->enclosing($keyword)];
        $values = [];

        for ($place = array_flip($marks)[$keyword]; $tokens->is($marks[$place], ...self::WITHIN); ++$place) {
            $values = $tokens->is($marks[$place], '=')
                ? [...$values, [$marks[$place] - 1, $marks[$place + 1] - 1]]
                : $values;
        }

        return $values;
    }

    /**
     * The symbol of a value a `const` or a `case` names: a class-like's
     * constant or backed case, or a global constant; nothing for a `case` of
     * a `switch`, or a constant of an anonymous class.
     */
    private function symbolOf(int $keyword, string $name): Symbol|Unnamed|NotGiven
    {
        $tokens = $this->source->tokens();
        $shape = $this->source->shape();
        $opener = $tokens->enclosing($keyword);
        $case = $tokens->is($keyword, T_CASE);

        return match (true) {
            ! $shape->isClassBody($opener) => $case
                ? NotGiven::value()
                : Symbol::globalConstant($this->source->scope()->declared($name)),
            $case => Symbol::enumCase($shape->classOf($opener)->name(), $name),
            default => Symbol::constant($shape->classOf($opener)->name(), $name),
        };
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

    private function spanning(Symbol $symbol, int $from, int $to): ValueDeclaration
    {
        return ValueDeclaration::of(
            $symbol,
            Line::of($this->source->tokens()->line($from)),
            Line::of($this->source->tokens()->line($to)),
        );
    }
}
