<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_filter;
use function array_map;
use function array_pop;
use function array_push;
use function array_values;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use PhpToken;

use function sprintf;

/**
 * Every named function and method a PHP file declares, with the lines from its
 * `function` keyword to its closing brace, read from its tokens. A closure has
 * no name, so a line inside one is inside the function around it.
 *
 * @internal
 */
final readonly class Functions
{
    /** What opens a block, at the top or inside a function alike. */
    private const array OPENS = ['{', T_DOLLAR_OPEN_CURLY_BRACES];

    /**
     * @param list<array{string, int, int}> $spans     each function's name, first line and last line
     * @param list<array{string, int, int}> $qualified the same, each method's name after its class's short name
     */
    private function __construct(private array $spans, private array $qualified)
    {
    }

    public static function in(Contents $contents): self
    {
        $tokens = Identifiers::of(array_values(array_filter(
            PhpToken::tokenize($contents->text()),
            static fn(PhpToken $token): bool => ! $token->isIgnorable(),
        )));
        $read = Tokens::of($tokens);
        $spans = [];
        $blocks = [];
        $pending = [];

        foreach ($tokens as $at => $token) {
            $pending = match (true) {
                $token->is(T_FUNCTION) => [self::nameAfter($read, $at), $token->line, self::qualifiedAt($read, $at)],
                $token->is(';') => [],
                default => $pending,
            };

            if ($token->is(self::OPENS)) {
                $blocks[] = $pending;
                $pending = [];
            }

            if ($token->is('}')) {
                array_push($spans, ...self::spanOf(array_pop($blocks) ?? [], $token->line));
            }
        }

        return new self(
            array_map(static fn(array $span): array => [$span[0], $span[1], $span[2]], $spans),
            array_map(static fn(array $span): array => [$span[3], $span[1], $span[2]], $spans),
        );
    }

    /**
     * Each named function and method, by its name, a method's after its class's short name, with its first and
     * last line, in the order their blocks close.
     *
     * @return list<array{string, int, int}>
     */
    public function qualified(): array
    {
        return $this->qualified;
    }

    /** The innermost named function a line is in, or nothing where it is in none. */
    public function around(Line $line): string|Nameless
    {
        $span = $this->innermost($line);

        return $span instanceof Nameless ? $span : $span[0];
    }

    /** The line the innermost named function a line is in begins on, which tells it from any other of its file. */
    public function startAround(Line $line): Line|Nameless
    {
        $span = $this->innermost($line);

        return $span instanceof Nameless ? $span : Line::of($span[1]);
    }

    /**
     * The span of a block that closes on a line: a named function's name, first line and last line; none for any
     * other block.
     *
     * @param  array{}|array{string, int, string} $opened what opened the block: a function's name, first line and
     *                                                     name after its class's
     * @return list<array{string, int, int, string}>
     */
    private static function spanOf(array $opened, int $closes): array
    {
        return $opened !== [] && $opened[0] !== '' ? [[$opened[0], $opened[1], $closes, $opened[2]]] : [];
    }

    /** The name after a `function` keyword, a method's after its class's short name; nothing for a closure. */
    private static function qualifiedAt(Tokens $tokens, int $at): string
    {
        $name = self::nameAfter($tokens, $at);
        $class = Enclosing::classOf($tokens, $at);

        return $name === '' || $class instanceof Nameless ? $name : sprintf(Declarations::METHOD, $class, $name);
    }

    /**
     * The span of the innermost function a line is in: the first around it, as a block inside another closes
     * first; nothing where it is in none.
     *
     * @return array{string, int, int}|Nameless
     */
    private function innermost(Line $line): array|Nameless
    {
        foreach ($this->spans as $span) {
            if ($span[1] <= $line->number() && $line->number() <= $span[2]) {
                return $span;
            }
        }

        return Nameless::code();
    }

    /** The name after a `function` keyword, past a `&`; nothing for a closure. */
    private static function nameAfter(Tokens $tokens, int $at): string
    {
        $name = $tokens->functionName($at);

        return $name === Tokens::NONE ? '' : $tokens->text($name);
    }
}
