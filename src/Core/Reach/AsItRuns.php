<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use function explode;
use function implode;

use NightWorksIO\MutationGate\Core\File\Contents;

use function preg_match;
use function trim;

/**
 * A CI definition that runs the gate, read as it runs the gate: without its
 * comment lines, the lines that hold nothing but a comment between its nodes,
 * which no runner reads. Every other byte stays, blank lines and the commit
 * each action is pinned at among them, and so does a comment line inside a
 * value, or where a value written on the line before could carry on, or a
 * block scalar's lines, which are kept as they are written. Where the
 * definition holds a byte a runner may read as a line break, an indentation
 * or nothing (a carriage return, a tab, another control character, a byte
 * order mark, NEL, or a Unicode line or paragraph separator), or YAML whose
 * values this reader cannot bound line by line (DefinitionLine), it is read
 * whole, every line kept. Every other change is a change to how the gate
 * runs: a proof's key (ADR-0007, key item 6) and what the change reaches
 * (ADR-0005, decision 4) move with it.
 */
final readonly class AsItRuns
{
    /** A line that holds nothing but a comment. */
    private const string COMMENT = '/^ *#/u';

    /** A byte a runner may read as a line break, an indentation or nothing, or one that is not UTF-8. */
    private const string UNREAD = '/[\x00-\x09\x0B-\x1F\x7F\x{85}\x{2028}\x{2029}\x{FEFF}]/u';

    /** The definition as it runs the gate. */
    public static function text(Contents $definition): string
    {
        $written = $definition->text();
        $spoken = preg_match(self::UNREAD, $written) === 0 ? self::spoken(explode("\n", $written)) : Unread::line();

        return $spoken instanceof Unread ? $written : implode("\n", $spoken);
    }

    /** Whether two versions of a definition run the gate alike, differing at most in their comment lines. */
    public static function alike(Contents $before, Contents $after): bool
    {
        return self::text($before) === self::text($after);
    }

    /**
     * The lines a runner reads, or unread where one line's part cannot be told.
     *
     * @param list<string> $lines
     *
     * @return list<string>|Unread
     */
    private static function spoken(array $lines): array|Unread
    {
        $kept = [];
        $last = Unread::line();
        $inBlock = false;

        foreach ($lines as $line) {
            $indent = DefinitionLine::read($line)->indent();
            $inBlock = $inBlock && $last instanceof DefinitionLine && self::inBlock($last, $line, $indent);
            $carried = $last instanceof DefinitionLine && $last->carriedOnBy($indent);

            if ($inBlock || self::keptAsWritten($line, $carried)) {
                $kept[] = $line;

                continue;
            }

            if (preg_match(self::COMMENT, $line) === 1) {
                continue;
            }

            $read = DefinitionLine::read($line);

            if ($read->role() === LineRole::Unread || $carried) {
                return Unread::line();
            }

            $kept[] = $line;
            $last = $read;
            $inBlock = $read->role() === LineRole::StartsBlock;
        }

        return $kept;
    }

    /** Whether a line, so indented, is a line of the block scalar a line starts: blank, or written deeper. */
    private static function inBlock(DefinitionLine $header, string $line, int $indent): bool
    {
        return trim($line, ' ') === '' || $indent > $header->indent();
    }

    /**
     * Whether a line outside every block scalar is kept as it is written
     * without being read: a blank line, or a comment line where the value of
     * the line before could carry on.
     */
    private static function keptAsWritten(string $line, bool $carried): bool
    {
        return trim($line, ' ') === '' || ($carried && preg_match(self::COMMENT, $line) === 1);
    }
}
