<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function mb_strlen;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Hint\Change;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use PhpToken;

/**
 * Where on its lines each mutant of a file is, to the column, found by
 * matching the tokens its diff changed against the file's tokens on those
 * lines. A mutant it cannot place there spans its lines from the first column
 * to the end. Columns count characters from 1, and the end is the column
 * after the last. The file is read once for all its mutants.
 */
final readonly class Columns
{
    /**
     * @param list<array{text: string, line: int, column: int}> $tokens every significant token, where it begins
     * @param list<string>                                        $lines  the file's lines
     */
    private function __construct(private array $tokens, private array $lines)
    {
    }

    public static function in(Contents $source): self
    {
        $tokens = [];
        $line = 1;
        $column = 1;

        foreach (PhpToken::tokenize($source->text()) as $token) {
            if (! $token->isIgnorable()) {
                $tokens[] = ['text' => $token->text, 'line' => $line, 'column' => $column];
            }

            $pieces = explode("\n", $token->text);
            $line += count($pieces) - 1;
            $column = count($pieces) > 1
                ? mb_strlen($pieces[count($pieces) - 1]) + 1
                : $column + mb_strlen($token->text);
        }

        return new self($tokens, explode("\n", $source->text()));
    }

    /** @return array{start: array{line: int, column: int}, end: array{line: int, column: int}} */
    public function of(Mutant $mutant): array
    {
        $first = $mutant->location()->start()->number();
        $end = $mutant->location()->end();
        $last = $end instanceof Line ? $end->number() : $first;
        $changed = Change::of($mutant->mutation()->diff())->changed();
        $tokens = array_values(array_filter(
            $this->tokens,
            static fn(array $token): bool => $token['line'] >= $first && $token['line'] <= $last,
        ));
        $at = self::find($tokens, $changed);

        if ($at < 0) {
            return [
                'start' => ['line' => $first, 'column' => 1],
                'end' => ['line' => $last, 'column' => $this->pastEnd($last)],
            ];
        }

        $final = $tokens[$at + count($changed) - 1];

        return [
            'start' => ['line' => $tokens[$at]['line'], 'column' => $tokens[$at]['column']],
            'end' => ['line' => $final['line'], 'column' => $final['column'] + mb_strlen($final['text'])],
        ];
    }

    /**
     * Where the first run of these tokens spelt as the changed ones are begins; -1 where none is.
     *
     * @param list<array{text: string, line: int, column: int}> $tokens
     * @param list<string>                                        $changed
     */
    private static function find(array $tokens, array $changed): int
    {
        $texts = array_map(static fn(array $token): string => $token['text'], $tokens);

        for ($at = 0; $changed !== [] && $at + count($changed) <= count($tokens); ++$at) {
            if (array_slice($texts, $at, count($changed)) === $changed) {
                return $at;
            }
        }

        return -1;
    }

    /** The column after the last character of a line; 1 for a line past the end of the file. */
    private function pastEnd(int $line): int
    {
        return array_key_exists($line - 1, $this->lines) ? mb_strlen($this->lines[$line - 1]) + 1 : 1;
    }
}
