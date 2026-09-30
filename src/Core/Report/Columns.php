<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_key_exists;
use function array_map;
use function array_slice;
use function count;
use function explode;
use function mb_strlen;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Hint\Change;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use PhpToken;

/**
 * Where on its lines a mutant is, to the column, found by matching the
 * tokens its diff changed against its file's tokens on those lines. A mutant
 * it cannot place there spans its lines from the first column to the end.
 * Columns count characters from 1, and the end is the column after the last.
 */
final readonly class Columns
{
    /**
     * @param array{line: int, column: int} $start
     * @param array{line: int, column: int} $end
     */
    private function __construct(private array $start, private array $end)
    {
    }

    public static function of(Mutant $mutant, Contents $source): self
    {
        $first = $mutant->location()->start()->number();
        $end = $mutant->location()->end();
        $last = $end instanceof Line ? $end->number() : $first;
        $changed = Change::of($mutant->mutation()->diff())->changed();
        $tokens = self::positioned($source, $first, $last);
        $at = self::find($tokens, $changed);

        if ($at < 0) {
            return self::lines($source, $first, $last);
        }

        $final = $tokens[$at + count($changed) - 1];

        return new self(
            ['line' => $tokens[$at]['line'], 'column' => $tokens[$at]['column']],
            ['line' => $final['line'], 'column' => $final['column'] + mb_strlen($final['token']->text)],
        );
    }

    /** @return array{line: int, column: int} */
    public function start(): array
    {
        return $this->start;
    }

    /** @return array{line: int, column: int} */
    public function end(): array
    {
        return $this->end;
    }

    /**
     * Where the first run of these tokens spelt as the changed ones are begins; -1 where none is.
     *
     * @param list<array{token: PhpToken, line: int, column: int}> $tokens
     * @param list<string>                                        $changed
     */
    private static function find(array $tokens, array $changed): int
    {
        $texts = array_map(static fn(array $token): string => $token['token']->text, $tokens);

        for ($at = 0; $changed !== [] && $at + count($changed) <= count($tokens); ++$at) {
            if (array_slice($texts, $at, count($changed)) === $changed) {
                return $at;
            }
        }

        return -1;
    }

    /**
     * The significant tokens that begin on these lines, each with the line and column it begins at.
     *
     * @return list<array{token: PhpToken, line: int, column: int}>
     */
    private static function positioned(Contents $source, int $first, int $last): array
    {
        $positioned = [];
        $line = 1;
        $column = 1;

        foreach (PhpToken::tokenize($source->text()) as $token) {
            if (! $token->isIgnorable() && $line >= $first && $line <= $last) {
                $positioned[] = ['token' => $token, 'line' => $line, 'column' => $column];
            }

            $pieces = explode("\n", $token->text);
            $line += count($pieces) - 1;
            $column = count($pieces) > 1
                ? mb_strlen($pieces[count($pieces) - 1]) + 1
                : $column + mb_strlen($token->text);
        }

        return $positioned;
    }

    /** From the first column of the first line to past the end of the last. */
    private static function lines(Contents $source, int $first, int $last): self
    {
        $lines = explode("\n", $source->text());
        $text = array_key_exists($last - 1, $lines) ? $lines[$last - 1] : '';

        return new self(['line' => $first, 'column' => 1], ['line' => $last, 'column' => mb_strlen($text) + 1]);
    }
}
