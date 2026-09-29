<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function array_find_key;
use function array_map;
use function array_slice;
use function explode;
use function hash;
use function implode;
use function is_int;
use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;

use function preg_match;
use function preg_replace;
use function sprintf;
use function str_starts_with;
use function trim;

/**
 * The gate's own id of a mutant: twelve lowercase hex characters that name
 * the same mutant on every machine, and survive code above it moving.
 */
final readonly class MutantId
{
    private const string ALGORITHM = 'sha256';

    private const int LENGTH = 12;

    private const string SPELLING = '/^[0-9a-f]{12}$/D';

    /** The line a unified diff's first hunk starts with; what comes before it is the header. */
    private const string HUNK = '@@';

    private function __construct(private string $value) {}

    /**
     * The id of a mutant, from its repository-relative path, its mutator by
     * the runner's full name for it, the removed and added lines of its diff
     * with whitespace collapsed, and how many mutants in the same file before
     * it share all three. That count starts at 0, in the order of the
     * mutants' lines, and the id with occurrence 0 is the key to count by.
     * Each field is hashed with its length before it, so no two lists of
     * fields hash alike.
     */
    public static function hash(Path $file, string $mutator, string $diff, int $occurrence): self
    {
        $fields = [$file->value(), $mutator, ...self::changedLinesOf($diff), sprintf('%d', $occurrence)];
        $canonical = implode("\n", array_map(static fn(string $field): string => sprintf('%d:%s', mb_strlen($field), $field), $fields));

        return new self(mb_substr(hash(self::ALGORITHM, $canonical), 0, self::LENGTH));
    }

    /** An id as a config, a report or a command line writes it. */
    public static function parse(string $id): self|CannotJudge
    {
        if (preg_match(self::SPELLING, $id) !== 1) {
            return CannotJudge::because(sprintf('"%s" is not a mutant id. An id is twelve lowercase hex characters, as every report prints it.', $id));
        }

        return new self($id);
    }

    public function value(): string
    {
        return $this->value;
    }

    /**
     * The removed and added lines of a diff, each with its sign and with every
     * run of whitespace collapsed to one space.
     *
     * @return list<string>
     */
    private static function changedLinesOf(string $diff): array
    {
        $lines = explode("\n", $diff);
        $hunk = array_find_key($lines, static fn(string $line): bool => str_starts_with($line, self::HUNK));
        $changed = [];

        foreach (is_int($hunk) ? array_slice($lines, $hunk) : $lines as $line) {
            if (str_starts_with($line, '-') || str_starts_with($line, '+')) {
                $changed[] = sprintf('%s%s', mb_substr($line, 0, 1), trim(preg_replace('/\s+/u', ' ', mb_substr($line, 1)) ?? ''));
            }
        }

        return $changed;
    }
}
