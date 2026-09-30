<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hint;

use function array_filter;
use function array_find_key;
use function array_map;
use function array_reverse;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function implode;
use function is_int;
use function mb_strcut;
use function mb_substr;

use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\Mutant\Hunks;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Php\Names;
use PhpToken;

use function sprintf;
use function str_starts_with;
use function trim;

/**
 * What a mutant's diff changed, read from its tokens: the lines it removed and
 * added, the tokens of the original that differ, and the expression around
 * them, as far as the nearest bracket, comma, statement or boolean operator.
 */
final readonly class Change
{
    /** What PHP code is read after, for a line that is not a file. */
    private const string OPENING = '<?php ';

    /** Where an expression stops, outside brackets. */
    private const array STOPS = [
        ',', ';', '{', '}', '?', ':', '=',
        T_DOUBLE_ARROW, T_BOOLEAN_AND, T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR, T_COALESCE,
        T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_CONCAT_EQUAL, T_MOD_EQUAL, T_POW_EQUAL,
        T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL, T_COALESCE_EQUAL,
        T_RETURN, T_IF, T_ELSEIF, T_WHILE, T_FOR, T_FOREACH, T_MATCH, T_ECHO, T_PRINT, T_THROW, T_YIELD, T_NEW,
    ];

    /** What opens a bracket. */
    private const array OPENS = ['(', '['];

    /** What closes one. */
    private const array CLOSES = [')', ']'];

    /**
     * @param list<PhpToken> $before the original's tokens
     * @param int            $from   where the tokens that differ begin
     * @param int            $to     where they end, exclusive
     */
    private function __construct(
        private string $code,
        private string $removed,
        private string $added,
        private array $before,
        private int $from,
        private int $to,
    ) {
    }

    public static function of(string $diff): self
    {
        $body = Hunks::linesOf($diff);
        $removed = self::signed($body, '-');
        $added = self::signed($body, '+');
        $code = sprintf('%s%s', self::OPENING, $removed);
        $before = self::tokensOf($code);
        $after = self::tokensOf(sprintf('%s%s', self::OPENING, $added));
        $same = self::sharedPrefix($before, $after);
        $tail = self::sharedPrefix(
            array_slice(array_reverse($before), 0, count($before) - $same),
            array_slice(array_reverse($after), 0, count($after) - $same),
        );

        return new self($code, $removed, $added, $before, $same, count($before) - $tail);
    }

    /** The lines the mutant removed, joined and trimmed; nothing where it only added. */
    public function removed(): string
    {
        return $this->removed;
    }

    /** The lines it put in their place, joined and trimmed; nothing where it only removed. */
    public function added(): string
    {
        return $this->added;
    }

    /**
     * The original's tokens that differ, as they are spelt.
     *
     * @return list<string>
     */
    public function changed(): array
    {
        return array_map(
            static fn(PhpToken $token): string => $token->text,
            array_slice($this->before, $this->from, $this->to - $this->from),
        );
    }

    /** The original's code that differs, such as `<` or `3`; the whole removed line where nothing does. */
    public function original(): string
    {
        $changed = $this->spelt($this->from, $this->to);

        return $changed === '' ? $this->removed : $changed;
    }

    /** The original expression around what differs, such as `$amount < $limit`. */
    public function expression(): string
    {
        $start = $this->from;
        $end = $this->to;

        while ($start > 0 && ! $this->endsLeftward($start - 1)) {
            --$start;
        }

        while ($end < count($this->before) && ! $this->endsRightward($end)) {
            ++$end;
        }

        return $this->spelt($start, $end);
    }

    /** The function or method the original calls where it differs, such as `save`; nameless where it calls none. */
    public function call(): string|Nameless
    {
        $tokens = array_slice($this->before, $this->from, $this->to - $this->from);
        $called = array_find_key(
            $tokens,
            static fn(PhpToken $token, int $at): bool => $token->is(Names::UNRELATIVE)
                && array_slice($tokens, $at + 1, 1) !== []
                && $tokens[$at + 1]->is('('),
        );

        return is_int($called) ? $this->lastSegmentOf($tokens[$called]->text) : Nameless::code();
    }

    /**
     * The lines of a diff's body that carry a sign, without it, trimmed and joined.
     *
     * @param list<string> $body
     */
    private static function signed(array $body, string $sign): string
    {
        $lines = array_filter($body, static fn(string $line): bool => str_starts_with($line, $sign));

        return trim(implode(' ', array_map(static fn(string $line): string => trim(mb_substr($line, 1)), $lines)));
    }

    /** @return list<PhpToken> */
    private static function tokensOf(string $code): array
    {
        return array_values(array_filter(
            array_slice(PhpToken::tokenize($code), 1),
            static fn(PhpToken $token): bool => ! $token->isIgnorable(),
        ));
    }

    /**
     * How many tokens two lists begin with alike.
     *
     * @param list<PhpToken> $one
     * @param list<PhpToken> $other
     */
    private static function sharedPrefix(array $one, array $other): int
    {
        $shared = 0;

        while ($shared < count($one) && $shared < count($other) && $one[$shared]->text === $other[$shared]->text) {
            ++$shared;
        }

        return $shared;
    }

    /** The original's code from one token to before another, as it is written. */
    private function spelt(int $from, int $to): string
    {
        if ($from >= $to) {
            return '';
        }

        $first = $this->before[$from];
        $last = $this->before[$to - 1];

        return mb_strcut($this->code, $first->pos, $last->pos + Bytes::length($last->text) - $first->pos);
    }

    private function lastSegmentOf(string $name): string
    {
        $segments = explode('\\', $name);

        return $segments[count($segments) - 1];
    }

    /** Whether the expression ends before this token, reading leftward: an open bracket or a stop outside brackets. */
    private function endsLeftward(int $at): bool
    {
        $depth = 0;

        for ($token = $at; $token < $this->from; ++$token) {
            $depth += $this->before[$token]->is(self::OPENS) ? 1 : 0;
            $depth -= $this->before[$token]->is(self::CLOSES) ? 1 : 0;
        }

        return $depth > 0 || ($depth === 0 && $this->before[$at]->is(self::STOPS));
    }

    /** Whether the expression ends at this token, reading rightward: a close bracket or a stop outside brackets. */
    private function endsRightward(int $at): bool
    {
        $depth = 0;

        for ($token = $this->to; $token <= $at; ++$token) {
            $depth += $this->before[$token]->is(self::OPENS) ? 1 : 0;
            $depth -= $this->before[$token]->is(self::CLOSES) ? 1 : 0;
        }

        return $depth < 0 || ($depth === 0 && $this->before[$at]->is(self::STOPS));
    }
}
