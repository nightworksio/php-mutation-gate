<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_fill_keys;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_last;
use function array_pop;
use function array_unique;
use function array_values;
use function count;

use Countable;

use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function min;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Format\Bytes;

use function pack;

use PhpToken;

use function range;
use function sort;
use function sprintf;
use function str_replace;
use function trim;
use function unpack;

/**
 * A PHP file's significant tokens, in order, each with the bracket it stands
 * inside and each bracket with where it closes: each token's kind and text
 * kept in lists, and where it begins packed into bytes, so a codebase's
 * files held at once take a fraction of what as many token objects take.
 *
 * @internal
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

    /** How each token's byte, and each token's line, is packed: an unsigned 32-bit little-endian integer. */
    private const string PACKED = 'V';

    /** How every token's bytes, or every token's lines, are packed, one after another. */
    private const string PACKED_ALL = 'V*';

    /** How many bytes a packed byte or line takes. */
    private const int PACKED_BYTES = 4;

    /**
     * @param list<int>       $ids       each token's kind, in order
     * @param list<string>    $texts     each token's text, in order
     * @param string          $offsets   the byte each token begins at, packed in order
     * @param string          $lines     the line each token begins on, packed in order
     * @param array<int, int> $enclosing where the bracket each token stands inside opens, by the token's index
     * @param array<int, int> $closing   where each bracket closes, by where it opens, or past the last token
     *                                   where it never does
     * @param array<int, list<int>>        $byId   where each token stands, in order, by its id
     * @param array<array-key, list<int>>  $byText where each token stands, in order, by its text
     */
    private function __construct(
        private array $ids,
        private array $texts,
        private string $offsets,
        private string $lines,
        private array $enclosing,
        private array $closing,
        private array $byId,
        private array $byText,
        private NameWords $words,
    ) {
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
        $byId = [];
        $byText = [];
        $ids = [];
        $texts = [];
        $offsets = [];
        $lines = [];
        foreach ($tokens as $at => $token) {
            $enclosing[$at] = array_last($open) ?? self::NONE;
            $byId[$token->id][] = $at;
            $byText[$token->text][] = $at;
            $ids[] = $token->id;
            $texts[] = $token->text;
            $offsets[] = $token->pos;
            $lines[] = $token->line;

            if ($token->is(self::CLOSES)) {
                $closing[array_pop($open) ?? self::NONE] = $at;
            }

            if ($token->is(self::OPENS)) {
                $open[] = $at;
            }
        }

        return new self(
            $ids,
            $texts,
            pack(self::PACKED_ALL, ...$offsets),
            pack(self::PACKED_ALL, ...$lines),
            $enclosing,
            $closing + array_fill_keys($open, count($tokens)),
            $byId,
            $byText,
            NameWords::of($tokens),
        );
    }

    /**
     * Where each token of these kinds stands, in order.
     *
     * @return list<int>
     */
    public function indicesOf(int|string ...$kinds): array
    {
        $found = [];

        foreach ($kinds as $kind) {
            $index = is_string($kind) ? $this->byText : $this->byId;
            $found = [...$found, ...array_key_exists($kind, $index) ? $index[$kind] : []];
        }

        sort($found);

        return array_values(array_unique($found));
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
            fn(int $enclosing, int $at): bool => $enclosing === $opener && $this->is($at, ...$kinds),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /** The words the names the file spells end in. */
    public function words(): NameWords
    {
        return $this->words;
    }

    /** How many tokens there are. */
    public function count(): int
    {
        return count($this->ids);
    }

    /** Whether some bracket encloses the token at an index. */
    public function isEnclosed(int $at): bool
    {
        return array_key_exists($at, $this->enclosing) && $this->enclosing[$at] !== self::NONE;
    }

    /** Whether a token stands at an index, and is of one of these kinds. */
    public function is(int $at, int|string ...$kinds): bool
    {
        return array_key_exists($at, $this->ids)
            && (in_array($this->ids[$at], $kinds, strict: true) || in_array($this->texts[$at], $kinds, strict: true));
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
        return $this->texts[$at];
    }

    /**
     * The text of the string literal at an index without its quotes, each
     * escaped backslash read as one, as a name written in a string reads.
     */
    public function unquoted(int $at): string
    {
        return str_replace('\\\\', '\\', trim($this->texts[$at], '\'"'));
    }

    /** @return list<string> the text of each token, in order */
    public function texts(): array
    {
        return $this->texts;
    }

    /** The byte the token at an index begins at, from 0. */
    public function offset(int $at): int
    {
        return self::unpacked($this->offsets, $at);
    }

    /** The line the token at an index begins on, from 1. */
    public function line(int $at): int
    {
        return self::unpacked($this->lines, $at);
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

        foreach ($this->indices($from, $to) as $at) {
            $offset = self::unpacked($this->offsets, $at);
            $spelt = sprintf('%s%s%s', $spelt, $offset > $end ? ' ' : '', $this->texts[$at]);
            $end = $offset + Bytes::length($this->texts[$at]);
        }

        return $spelt;
    }

    /**
     * The index of each token from one index up to another, in order.
     *
     * @return list<int>
     */
    public function indices(int $from, int $to): array
    {
        $end = min($to, count($this->ids));

        return $from < $end ? range($from, $end - 1) : [];
    }

    /** The integer packed at a token's index. */
    private static function unpacked(string $packed, int $at): int
    {
        $read = unpack(self::PACKED, $packed, $at * self::PACKED_BYTES);

        return is_array($read) && is_int($read[1]) ? $read[1] : 0;
    }
}
