<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use NightWorksIO\MutationGate\Core\File\Contents;

use function preg_replace;

/**
 * Whether a change to a CI definition only moved its action pins: the lines
 * `uses: owner/repo@<40-hex sha>`, with or without a comment after them. A
 * pin decides which revision of an action runs, not how the gate runs.
 */
final readonly class Pins
{
    /** An action pinned at a full commit, and any comment after the pin. */
    private const string PIN = <<<'REGEX'
        ~^(\s*(?:-\s+)?uses:\s*["']?[^@\s"']+@)[0-9a-f]{40}(["']?)(?:\s+\#.*)?$~mux
        REGEX;

    /** A pinned line with its commit and its comment taken out. */
    private const string UNPINNED = '$1$2';

    public static function onlyMoved(Contents $before, Contents $after): bool
    {
        return self::unpinned($before) === self::unpinned($after);
    }

    /**
     * A CI definition with every action pinned at a full commit read without
     * that commit and the comment after it, so a moved pin is no change.
     */
    public static function unpinned(Contents $definition): string
    {
        return preg_replace(self::PIN, self::UNPINNED, $definition->text()) ?? $definition->text();
    }
}
