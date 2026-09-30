<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\File;

use function sprintf;

/** The directory the gate keeps its own files under in a project, wherever nothing else is configured. */
final readonly class Workspace
{
    private const string ROOT = '.mutation-gate';

    private const string LEDGER = 'ledger';

    /** The directory itself. */
    public static function root(): Path
    {
        return Path::of(self::ROOT);
    }

    /** Where the proofs are kept when no store is chosen: `.mutation-gate/ledger`. */
    public static function ledger(): Path
    {
        return Path::of(sprintf('%s/%s', self::ROOT, self::LEDGER));
    }
}
