<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_fill_keys;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_last;
use function array_map;
use function array_pop;
use function array_slice;
use function array_values;
use function count;

use Countable;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Format\Bytes;
use PhpToken;

use function sprintf;
use function str_replace;
use function trim;

/**
 * A PHP file's significant tokens, in order, each with the bracket it stands
 * inside and each bracket with where it closes.
 */
final readonly class Tokens implements Countable
{
    /** Where a call's first argument stands, from the name it calls: past the name and its `(`. */
    public const int ARGUMENT = 2;

    /** What declares a class-like: a class, an interface, a trait or an enum. */
    public const array CLASS_LIKE = [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM];

    /** What ends or opens a statement. */
    public const array STATEMENT_BOUNDS = [';', '{', '}'];

    /** At no token's index: where a token that no bracket encloses stands, or what is not found. */
    public const int NONE = -1;

    /**
     * What opens a bracket, an attribute group's `#[` among them. A token is
     * matched by its text too, so `{` also opens the `{$` of a string.
     */
    public const array OPENS = ['(', '[', '{', T_ATTRIBUTE, T_DOLLAR_OPEN_CURLY_BRACES];

    /** What closes one. */
    public const array CLOSES = [')', ']', '}'];

    /**
     * @param list<PhpToken>  $tokens
     * @param array<int, int> $enclosing where the bracket each token stands inside opens, by the token's index
     * @param array<int, int> $closing   where each bracket closes, by where it opens, or past the last token
     *                                   where it never does
     */
    private function __construct(private array $tokens, private array $enclosing, private array $closing)
    {
    }

    /**
     * A file's significant tokens: every token but whitespace and comments,
     * each semi-reserved word that stands as a name read as one.
     */
    public static function in(Contents $file): self
    {
        return self::of(Identifiers::of(array_values(array_filter(
            PhpToken::tokenize($file->text()),
            static fn(PhpToken $token): bool => ! $token->isIgnorable(),
        ))));
    }

    /** @param list<PhpToken> $tokens a file's significant tokens, in order */
    public static function of(array $tokens): self
    {
        $open = [];
        $enclosing = [];
        $closing = [];

        foreach ($tokens as $at => $token) {
            $enclosing[$at] = array_last($open) ?? self::NONE;

            if ($token->is(self::CLOSES)) {
                $closing[array_pop($open) ?? self::NONE] = $at;
            }

            if ($token->is(self::OPENS)) {
                $open[] = $at;
            }
        }

        return new self($tokens, $enclosing, $closing + array_fill_keys($open, count($tokens)));
    }

    /**
     * Where each token of these kinds stands, in order.
     *
     * @return list<int>
     */
    public function indicesOf(int|string ...$kinds): array
    {
        return array_keys(array_filter($this->tokens, static fn(PhpToken $token): bool => $token->is($kinds)));
    }

    /**
     * Where each token of these kinds stands directly inside the bracket that
     * opens at an index, in order.
     *
     * @return list<int>
     */
    public function inside(int $opener, int|string ...$kinds): array
    {
        return array_keys(array_filter(
            $this->enclosing,
            fn(int $enclosing, int $at): bool => $enclosing === $opener && $this->tokens[$at]->is($kinds),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /** How many tokens there are. */
    public function count(): int
    {
        return count($this->tokens);
    }

    /** Whether some bracket encloses the token at an index. */
    public function isEnclosed(int $at): bool
    {
        return $this->enclosing[$at] !== self::NONE;
    }

    /** Whether a token stands at an index, and is of one of these kinds. */
    public function is(int $at, int|string ...$kinds): bool
    {
        return array_key_exists($at, $this->tokens) && $this->tokens[$at]->is($kinds);
    }

    /**
     * Where the name a `function` keyword declares stands: the token between
     * the keyword, past a `&`, and its parameter list; or no index where none
     * does, as for a closure. A method may take a semi-reserved word such as
     * `and` or `for` as its name, which PHP does not read as a `T_STRING`.
     */
    public function functionName(int $keyword): int
    {
        $name = $this->is($keyword + 1, '&') ? $keyword + 2 : $keyword + 1;

        return $this->is($name + 1, '(') && ! $this->is($name, '(') ? $name : self::NONE;
    }

    /** The text of the token at an index. */
    public function text(int $at): string
    {
        return $this->tokens[$at]->text;
    }

    /**
     * The text of the string literal at an index without its quotes, each
     * escaped backslash read as one, as a name written in a string reads.
     */
    public function unquoted(int $at): string
    {
        return str_replace('\\\\', '\\', trim($this->tokens[$at]->text, '\'"'));
    }

    /** @return list<string> the text of each token, in order */
    public function texts(): array
    {
        return array_map(static fn(PhpToken $token): string => $token->text, $this->tokens);
    }

    /** The byte the token at an index begins at, from 0. */
    public function offset(int $at): int
    {
        return $this->tokens[$at]->pos;
    }

    /** The line the token at an index begins on, from 1. */
    public function line(int $at): int
    {
        return $this->tokens[$at]->line;
    }

    /** Where the bracket a token stands inside opens, or an index no token has where none encloses it. */
    public function enclosing(int $at): int
    {
        return $this->enclosing[$at];
    }

    /** Where the bracket that opens at an index closes. */
    public function closing(int $opener): int
    {
        return $this->closing[$opener];
    }

    /**
     * The tokens from one index up to another, as written, with each run of
     * whitespace and comments between two of them read as one space.
     */
    public function spelt(int $from, int $to): string
    {
        $spelt = '';
        $end = PHP_INT_MAX;

        foreach (array_slice($this->tokens, $from, $to - $from) as $token) {
            $spelt = sprintf('%s%s%s', $spelt, $token->pos > $end ? ' ' : '', $token->text);
            $end = $token->pos + Bytes::length($token->text);
        }

        return $spelt;
    }
}
