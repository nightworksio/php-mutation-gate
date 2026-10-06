<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use function array_filter;
use function explode;
use function implode;

use NightWorksIO\MutationGate\Core\File\Contents;

use function preg_match;
use function preg_replace;

/**
 * A CI definition that runs the gate, read as it runs the gate: without its
 * comments, its blank lines, and the commit each action is pinned at, the
 * lines `uses: owner/repo@<40-hex sha>`, with or without a comment after
 * them. A comment or a pin decides nothing of how the gate runs, so a change
 * to them alone is no change to a proof's key (ADR-0007, key item 7) and
 * reaches nothing (ADR-0005, decision 4).
 */
final readonly class AsItRuns
{
    /** A line that holds nothing but a comment, or nothing at all. */
    private const string SILENT = '/^\s*(?:#.*)?$/u';

    /** An action pinned at a full commit, and any comment after the pin. */
    private const string PIN = <<<'REGEX'
        ~^(\s*(?:-\s+)?uses:\s*["']?[^@\s"']+@)[0-9a-f]{40}(["']?)(?:\s+\#.*)?$~mux
        REGEX;

    /** A pinned line with its commit and its comment taken out. */
    private const string UNPINNED = '$1$2';

    /** The definition as it runs the gate. */
    public static function text(Contents $definition): string
    {
        $lines = array_filter(
            explode("\n", $definition->text()),
            static fn(string $line): bool => preg_match(self::SILENT, $line) !== 1,
        );
        $spoken = implode("\n", $lines);

        return preg_replace(self::PIN, self::UNPINNED, $spoken) ?? $spoken;
    }

    /** Whether two versions of a definition run the gate alike, differing at most in comments, blank lines and pins. */
    public static function alike(Contents $before, Contents $after): bool
    {
        return self::text($before) === self::text($after);
    }
}
