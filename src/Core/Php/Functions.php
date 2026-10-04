<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_filter;
use function array_pop;
use function array_values;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use PhpToken;

/**
 * Every named function and method a PHP file declares, with the lines from its
 * `function` keyword to its closing brace, read from its tokens. A closure has
 * no name, so a line inside one is inside the function around it.
 */
final readonly class Functions
{
    /** What opens a block, at the top or inside a function alike. */
    private const array OPENS = ['{', T_DOLLAR_OPEN_CURLY_BRACES];

    /** @param list<array{string, int, int}> $spans each function's name, first line and last line */
    private function __construct(private array $spans)
    {
    }

    public static function in(Contents $contents): self
    {
        $tokens = array_values(array_filter(
            PhpToken::tokenize($contents->text()),
            static fn(PhpToken $token): bool => ! $token->isIgnorable(),
        ));
        $read = Tokens::of($tokens);
        $spans = [];
        $blocks = [];
        $pending = [];

        foreach ($tokens as $at => $token) {
            $pending = $token->is(T_FUNCTION) ? [self::nameAfter($read, $at), $token->line] : $pending;
            $pending = $token->is(';') ? [] : $pending;

            if ($token->is(self::OPENS)) {
                $blocks[] = $pending;
                $pending = [];
            }

            $closed = $token->is('}') ? array_pop($blocks) ?? [] : [];
            $spans = $closed !== [] && $closed[0] !== '' ? [...$spans, [$closed[0], $closed[1], $token->line]] : $spans;
        }

        return new self($spans);
    }

    /** The innermost named function a line is in, or nothing where it is in none. */
    public function around(Line $line): string|Nameless
    {
        $at = $this->innermost($line);

        return $at < 0 ? Nameless::code() : $this->spans[$at][0];
    }

    /** The line the innermost named function a line is in begins on, which tells it from any other of its file. */
    public function startAround(Line $line): Line|Nameless
    {
        $at = $this->innermost($line);

        return $at < 0 ? Nameless::code() : Line::of($this->spans[$at][1]);
    }

    /** Where among the spans the innermost function a line is in stands; -1 where it is in none. */
    private function innermost(Line $line): int
    {
        $found = -1;
        $first = 0;

        foreach ($this->spans as $at => [, $from, $to]) {
            $inside = $from <= $line->number() && $line->number() <= $to && $from >= $first;
            [$found, $first] = $inside ? [$at, $from] : [$found, $first];
        }

        return $found;
    }

    /** The name after a `function` keyword, past a `&`; nothing for a closure. */
    private static function nameAfter(Tokens $tokens, int $at): string
    {
        $name = $tokens->functionName($at);

        return $name === Tokens::NONE ? '' : $tokens->text($name);
    }
}
