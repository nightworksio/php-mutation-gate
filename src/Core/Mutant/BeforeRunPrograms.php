<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function hash;

use NightWorksIO\MutationGate\Core\File\Contents;

use function sprintf;

/**
 * The names a before-run proof gives the programs a child compiles, one for
 * every original of the same text and one for each mutant by its key, and
 * the setting that keeps every error out of the child's answers (ADR-0130).
 *
 * @internal
 */
final readonly class BeforeRunPrograms
{
    /** The setting that shows no error among a child's answers. */
    public const string QUIET = 'display_errors=0';

    private const string ORIGINAL = 'original:%s';

    private const string MUTANT = 'mutant:%s';

    private function __construct()
    {
    }

    /** An original's name among the programs, one for every mutant of the same text. */
    public static function original(Contents $original): string
    {
        return sprintf(self::ORIGINAL, hash('sha256', $original->text()));
    }

    /** A mutant's name among the programs, by its key. */
    public static function mutant(string $key): string
    {
        return sprintf(self::MUTANT, $key);
    }
}
