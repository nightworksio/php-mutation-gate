<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules\ListLayout;

use function array_key_exists;
use function in_array;

use PhpToken;

use function preg_match_all;

use const PREG_OFFSET_CAPTURE;
use const T_FUNCTION;

/**
 * Where things sit in one file's text: the offset each line begins at, the
 * column of the first token on each line, and the offsets of every `)` and
 * every `function` keyword. Columns count from zero, as SonarCloud's do.
 */
final readonly class Source
{
    /**
     * @param list<int> $lineStarts the offset each line begins at, the first line's at index 0
     * @param array<int, int> $firstColumns by line, the column of the first token that begins on it
     * @param list<int> $closers the offset of every `)`
     * @param list<int> $functions the offset of every `function` keyword
     */
    private function __construct(
        private array $lineStarts,
        private array $firstColumns,
        private array $closers,
        private array $functions,
    ) {
    }

    public static function of(string $code): self
    {
        preg_match_all('/\n/', $code, $newlines, PREG_OFFSET_CAPTURE);
        $lineStarts = [0];

        foreach ($newlines[0] as [, $offset]) {
            $lineStarts[] = $offset + 1;
        }

        $firstColumns = [];
        $closers = [];
        $functions = [];

        foreach (PhpToken::tokenize($code) as $token) {
            if ($token->isIgnorable()) {
                continue;
            }

            if (! array_key_exists($token->line, $firstColumns)) {
                $firstColumns[$token->line] = $token->pos - $lineStarts[$token->line - 1];
            }

            if ($token->is(')')) {
                $closers[] = $token->pos;
            }

            if ($token->is(T_FUNCTION)) {
                $functions[] = $token->pos;
            }
        }

        return new self($lineStarts, $firstColumns, $closers, $functions);
    }

    /** The column an offset on a line sits at. */
    public function column(int $line, int $offset): int
    {
        return $offset - $this->lineStarts[$line - 1];
    }

    /** The column of the first token that begins on a line, or zero where none does. */
    public function indentOf(int $line): int
    {
        return array_key_exists($line, $this->firstColumns) ? $this->firstColumns[$line] : 0;
    }

    /** The line an offset sits on. */
    public function lineOf(int $offset): int
    {
        $line = 1;

        foreach ($this->lineStarts as $index => $start) {
            if ($start > $offset) {
                break;
            }

            $line = $index + 1;
        }

        return $line;
    }

    /** The offset of the first `)` after an offset, or -1 where there is none. */
    public function closerAfter(int $offset): int
    {
        return $this->firstAfter($this->closers, $offset);
    }

    /** The offset of the first `function` keyword at or after an offset, or -1 where there is none. */
    public function functionFrom(int $offset): int
    {
        return $this->firstAfter($this->functions, $offset - 1);
    }

    /** Whether the token at an offset is a `)`. */
    public function isCloserAt(int $offset): bool
    {
        return in_array($offset, $this->closers, strict: true);
    }

    /** @param list<int> $offsets in ascending order */
    private function firstAfter(array $offsets, int $offset): int
    {
        foreach ($offsets as $candidate) {
            if ($candidate > $offset) {
                return $candidate;
            }
        }

        return -1;
    }
}
