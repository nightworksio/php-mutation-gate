<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_fill_keys;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_last;
use function array_pop;
use function array_slice;
use function count;
use function mb_strlen;

use PhpToken;

use function sprintf;

/**
 * A PHP file's significant tokens, in order, each with the bracket it stands
 * inside and each bracket with where it closes.
 */
final readonly class Tokens
{
    /** Where a token that no bracket encloses stands: at no token's index. */
    private const int OUTSIDE = -1;

    /**
     * What opens a bracket, an attribute group's `#[` among them. A token is
     * matched by its text too, so `{` also opens the `{$` of a string.
     */
    private const array OPENS = ['(', '[', '{', T_ATTRIBUTE, T_DOLLAR_OPEN_CURLY_BRACES];

    /** What closes one. */
    private const array CLOSES = [')', ']', '}'];

    /**
     * @param list<PhpToken>  $tokens
     * @param array<int, int> $enclosing where the bracket each token stands inside opens, by the token's index
     * @param array<int, int> $closing   where each bracket closes, by where it opens, or past the last token
     *                                   where it never does
     */
    private function __construct(private array $tokens, private array $enclosing, private array $closing)
    {
    }

    /** @param list<PhpToken> $tokens a file's significant tokens, in order */
    public static function of(array $tokens): self
    {
        $open = [];
        $enclosing = [];
        $closing = [];

        foreach ($tokens as $at => $token) {
            $enclosing[$at] = array_last($open) ?? self::OUTSIDE;

            if ($token->is(self::CLOSES)) {
                $closing[array_pop($open) ?? self::OUTSIDE] = $at;
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

    /** Whether a token stands at an index, and is of one of these kinds. */
    public function is(int $at, int|string ...$kinds): bool
    {
        return array_key_exists($at, $this->tokens) && $this->tokens[$at]->is($kinds);
    }

    /** The text of the token at an index. */
    public function text(int $at): string
    {
        return $this->tokens[$at]->text;
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
            $end = $token->pos + mb_strlen($token->text, '8bit');
        }

        return $spelt;
    }
}
