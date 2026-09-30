<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_filter;
use function array_pop;
use function array_slice;
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
    /** How a function's name is spelt after `function` and an optional `&`. */
    private const array NAMES = [T_STRING];

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
        $spans = [];
        $blocks = [];
        $pending = [];

        foreach ($tokens as $at => $token) {
            $pending = $token->is(T_FUNCTION) ? [self::nameAfter($tokens, $at), $token->line] : $pending;
            $pending = $token->is(';') ? [] : $pending;

            if ($token->is(TopLevel::OPENS)) {
                $blocks[] = $pending;
                $pending = [];
            }

            $closed = $token->is('}') ? array_pop($blocks) ?? [] : [];
            $spans = $closed !== [] && $closed[0] !== '' ? [...$spans, [$closed[0], $closed[1], $token->line]] : $spans;
        }

        return new self($spans);
    }

    /** The innermost named function a line is in, or nothing where it is in none. */
    public function around(Line $line): string
    {
        $name = '';
        $first = 0;

        foreach ($this->spans as [$function, $from, $to]) {
            $inside = $from <= $line->number() && $line->number() <= $to && $from >= $first;
            [$name, $first] = $inside ? [$function, $from] : [$name, $first];
        }

        return $name;
    }

    /**
     * The name after a `function` keyword, past a `&`; nothing for a closure.
     *
     * @param list<PhpToken> $tokens
     */
    private static function nameAfter(array $tokens, int $at): string
    {
        $name = array_slice($tokens, $at + 1, 2);
        $name = $name !== [] && $name[0]->is('&') ? array_slice($name, 1) : $name;

        return $name !== [] && $name[0]->is(self::NAMES) ? $name[0]->text : '';
    }
}
