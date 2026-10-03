<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_slice;
use function count;
use function explode;
use function mb_strlen;

use NightWorksIO\MutationGate\Core\Cluster\Span;
use NightWorksIO\MutationGate\Core\Cluster\Unplaced;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Hint\Change;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
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
    /** What ends or opens a statement, which a span of one statement holds none of. */
    private const array STATEMENT_BOUNDS = [';', '{', '}'];

    /**
     * @param list<array{text: string, line: int, column: int, bound: bool}> $tokens every significant token,
     *                                                                             where it begins and whether it
     *                                                                             ends or opens a statement
     * @param list<string>                                                     $lines  the file's lines
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
                $tokens[] = [
                    'text' => $token->text,
                    'line' => $line,
                    'column' => $column,
                    'bound' => $token->is(self::STATEMENT_BOUNDS),
                ];
            }

            $pieces = explode("\n", $token->text);
            $line += count($pieces) - 1;
            $column = count($pieces) > 1
                ? mb_strlen($pieces[count($pieces) - 1]) + 1
                : $column + mb_strlen($token->text);
        }

        return new self($tokens, explode("\n", $source->text()));
    }

    /**
     * Where a mutant's change is; for a kill a ledger proved, which keeps no
     * diff, its whole line.
     *
     * @return array{start: array{line: int, column: int}, end: array{line: int, column: int}}
     */
    public function of(Mutant|ProvedKill $mutant): array
    {
        [$first, $last] = $this->linesOf($mutant);
        $found = $this->found($mutant);

        return $found instanceof Unplaced
            ? [
                'start' => ['line' => $first, 'column' => 1],
                'end' => ['line' => $last, 'column' => $this->pastEnd($last)],
            ]
            : $found;
    }

    /**
     * Where a mutant's change is, from its first changed token to the end of
     * its last; nothing where those tokens are not found on its lines, or it
     * is a kill that keeps no diff.
     *
     * @return array{start: array{line: int, column: int}, end: array{line: int, column: int}}|Unplaced
     */
    public function found(Mutant|ProvedKill $mutant): array|Unplaced
    {
        [$at, $length] = $this->placed($mutant);

        if ($at < 0) {
            return Unplaced::mutant();
        }

        $final = $this->tokens[$at + $length - 1];

        return [
            'start' => ['line' => $this->tokens[$at]['line'], 'column' => $this->tokens[$at]['column']],
            'end' => ['line' => $final['line'], 'column' => $final['column'] + mb_strlen($final['text'])],
        ];
    }

    /**
     * The file's tokens a mutant changed, from the first to the last, where
     * they lie within one statement: no `;`, `{` or `}` among them but a `;`
     * that ends them. Nothing where the mutant cannot be placed, or where
     * its change runs past a statement.
     */
    public function span(Mutant $mutant): Span|Unplaced
    {
        [$at, $length] = $this->placed($mutant);
        $inner = $at < 0 ? [] : array_slice($this->tokens, $at, $length);
        $inner = $inner !== [] && $inner[count($inner) - 1]['text'] === ';' ? array_slice($inner, 0, -1) : $inner;

        foreach ($inner as $token) {
            if ($token['bound']) {
                return Unplaced::mutant();
            }
        }

        return $at < 0 ? Unplaced::mutant() : Span::of($at, $at + $length - 1);
    }

    /**
     * Where among the file's tokens those a mutant changed begin, -1 where
     * they are not found on its lines or it is a kill that keeps no diff,
     * and how many there are.
     *
     * @return array{int, int}
     */
    private function placed(Mutant|ProvedKill $mutant): array
    {
        [$first, $last] = $this->linesOf($mutant);
        $changed = $mutant instanceof Mutant ? Change::of($mutant->mutation()->diff())->changed() : [];
        $window = array_keys(array_filter(
            $this->tokens,
            static fn(array $token): bool => $token['line'] >= $first && $token['line'] <= $last,
        ));
        $found = $this->find(array_map(fn(int $at): string => $this->tokens[$at]['text'], $window), $changed);

        return [$found < 0 ? -1 : $window[$found], count($changed)];
    }

    /** @return array{int, int} the line a mutant starts on and the line it ends on, which is its first where unsaid */
    private function linesOf(Mutant|ProvedKill $mutant): array
    {
        $first = $mutant->location()->start()->number();
        $end = $mutant->location()->end();

        return [$first, $end instanceof Line ? $end->number() : $first];
    }

    /**
     * Where the first run of these texts spelt as the changed ones are begins; -1 where none is.
     *
     * @param list<string> $texts
     * @param list<string> $changed
     */
    private function find(array $texts, array $changed): int
    {
        for ($at = 0; $changed !== [] && $at + count($changed) <= count($texts); ++$at) {
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
