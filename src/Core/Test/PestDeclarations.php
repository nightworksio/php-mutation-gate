<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use function array_key_exists;
use function array_reverse;
use function array_unshift;
use function ltrim;
use function mb_strtolower;

use NightWorksIO\MutationGate\Core\Assertion\AssertionScan;
use NightWorksIO\MutationGate\Core\Assertion\TestAssertions;
use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Php\Names;
use NightWorksIO\MutationGate\Core\Php\Tokens;

use function preg_match;
use function preg_replace;
use function sprintf;
use function stripcslashes;

/**
 * Each Pest `it()` or `test()` a file calls with a literal description, at
 * its top or inside the closure of a `describe()` so called, by the method
 * Pest makes of its description under each `describe()` around it, from its
 * call to the end of the calls chained after it and its `;`.
 *
 * @internal
 */
final readonly class PestDeclarations
{
    /** Pest's function that describes the tests inside its closure, which puts nothing before its description. */
    private const array DESCRIBING = [TestAssertions::DESCRIBE => ''];

    /** How Pest puts a `describe()`'s description before that of a test inside it. */
    private const string DESCRIBED = '`%s` → %s';

    /** A literal whose escapes are those of single quotes: one that opens with one. */
    private const string SINGLE_QUOTED_LITERAL = "/^'/";

    /** The two escapes a single-quoted literal reads: a backslash or a quote after a backslash. */
    private const string SINGLE_QUOTED = "/\\\\([\\\\'])/";

    /** @return list<Declared> */
    public static function in(Tokens $tokens): array
    {
        $tests = [];

        foreach ($tokens->indicesOf(...Names::UNRELATIVE) as $at) {
            $description = self::described($tokens, $at, TestAssertions::DECLARING);

            if ($description instanceof NotGiven) {
                continue;
            }

            $around = self::describing($tokens, $at);
            $tests = $around instanceof NotGiven
                ? $tests
                : [...$tests, self::declared($tokens, $at, self::under($around, $description))];
        }

        return $tests;
    }

    /** A test whose call is at an index, with its chained calls and its `;`, under this description. */
    private static function declared(Tokens $tokens, int $at, string $description): Declared
    {
        $end = AssertionScan::chainEnd($tokens, $tokens->closing($at + 1));

        return Declared::between(
            TestMethod::pest($description),
            $tokens,
            $at,
            $tokens->is($end + 1, ';') ? $end + 1 : $end,
        );
    }

    /**
     * What a call at an index that begins a statement describes, with what
     * the function it calls puts before it, where it calls one of these with
     * a literal description alone as its first argument.
     *
     * @param array<string, string> $functions what each function puts before its description, by its name
     */
    private static function described(Tokens $tokens, int $at, array $functions): string|NotGiven
    {
        $function = mb_strtolower(ltrim($tokens->text($at), '\\'));
        $literal = $at + Tokens::ARGUMENT;
        $declares = array_key_exists($function, $functions)
            && ($at === 0 || $tokens->is($at - 1, ...Tokens::STATEMENT_BOUNDS))
            && $tokens->is($at + 1, '(')
            && $tokens->is($literal, T_CONSTANT_ENCAPSED_STRING)
            && $tokens->is($literal + 1, ',', ')');

        return $declares
            ? sprintf('%s%s', $functions[$function], self::unquoted($tokens->text($literal)))
            : NotGiven::value();
    }

    /**
     * The description of each `describe()` whose closure holds the token at
     * an index, the outermost first; none where anything else holds it.
     *
     * @return list<string>|NotGiven
     */
    private static function describing(Tokens $tokens, int $at): array|NotGiven
    {
        $around = [];
        $opener = $tokens->enclosing($at);

        while ($opener !== Tokens::NONE) {
            $arguments = $tokens->is($opener, '{') ? $tokens->enclosing($opener) : Tokens::NONE;
            $description = $arguments === Tokens::NONE
                ? NotGiven::value()
                : self::described($tokens, $arguments - 1, self::DESCRIBING);

            if ($description instanceof NotGiven) {
                return $description;
            }

            array_unshift($around, $description);
            $opener = $tokens->enclosing($arguments);
        }

        return $around;
    }

    /**
     * A test's description under the descriptions of the `describe()`s around it, as Pest writes it.
     *
     * @param list<string> $around the outermost first
     */
    private static function under(array $around, string $description): string
    {
        $described = $description;

        foreach (array_reverse($around) as $describe) {
            $described = sprintf(self::DESCRIBED, $describe, $described);
        }

        return $described;
    }

    /** A literal's text, read as PHP reads it: a single-quoted one's two escapes, and a double-quoted one's all. */
    private static function unquoted(string $literal): string
    {
        $text = Bytes::slice($literal, 1, Bytes::length($literal) - 2);

        return preg_match(self::SINGLE_QUOTED_LITERAL, $literal) === 1
            ? preg_replace(self::SINGLE_QUOTED, '$1', $text) ?? $text
            : stripcslashes($text);
    }
}
