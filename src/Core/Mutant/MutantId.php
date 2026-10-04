<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function array_map;
use function implode;
use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;

use function preg_match;
use function preg_match_all;
use function sprintf;
use function str_starts_with;

/**
 * The gate's own id of a mutant: twelve lowercase hex characters that name
 * the same mutant on every machine, and survive code above it moving.
 */
final readonly class MutantId
{
    private const int LENGTH = 12;

    /** How an id is written as an array key: behind a letter no hex character is. */
    private const string KEY = 'm%s';

    private const string SPELLING = '/^[0-9a-f]{12}$/D';

    /** A run of anything but whitespace. */
    private const string WORD = '/\S+/';

    private function __construct(private string $value)
    {
    }

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
        $sized = array_map(static fn(string $field): string => sprintf('%d:%s', mb_strlen($field), $field), $fields);
        $canonical = implode("\n", $sized);

        return new self(mb_substr(Digest::sha256Of($canonical)->value(), 0, self::LENGTH));
    }

    /** An id as a config, a report or a command line writes it. */
    public static function parse(string $id): self|CannotJudge
    {
        if (preg_match(self::SPELLING, $id) !== 1) {
            return CannotJudge::because(sprintf(
                '"%s" is not a mutant id. An id is twelve lowercase hex characters, as every report prints it.',
                $id,
            ));
        }

        return new self($id);
    }

    public function value(): string
    {
        return $this->value;
    }

    /**
     * The id as an array key: behind an `m`, which no hex character is, as
     * PHP stores an all-digit string key as an integer, which a merge or a
     * spread numbers afresh.
     */
    public function key(): string
    {
        return sprintf(self::KEY, $this->value);
    }

    /**
     * The removed and added lines of a diff, each with its sign and with every
     * run of whitespace collapsed to one space.
     *
     * @return list<string>
     */
    private static function changedLinesOf(string $diff): array
    {
        $changed = [];

        foreach (Hunks::linesOf($diff) as $line) {
            if (str_starts_with($line, '-') || str_starts_with($line, '+')) {
                preg_match_all(self::WORD, mb_substr($line, 1), $words);
                $changed[] = sprintf('%s%s', mb_substr($line, 0, 1), implode(' ', $words[0]));
            }
        }

        return $changed;
    }
}
