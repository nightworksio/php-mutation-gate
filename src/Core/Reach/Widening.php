<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use function sprintf;

/**
 * Why a change to a test file reaches every unit its tests run, rather than
 * only those its changed tests run (ADR-0065).
 *
 * @internal
 */
enum Widening
{
    /** The file is new. */
    case New;

    /** The file was renamed, which renames every test it holds. */
    case Renamed;

    /** The file cannot be read as it was, or as it is. */
    case Unread;

    /** The change moved code the file's tests share: an import, a hook, a helper, a data provider or a property. */
    case Shared;

    /** The change moved a test, and a test of the file may depend on another. */
    case Dependent;

    /** What a change to a test file says it reaches, and why. */
    private const string REACHED = '`%s` %s, so the %d files all its tests run are reached.';

    /** The reason a change to a test file so spelt gives, which reaches this many files. */
    public function reason(string $file, int $files): Reason
    {
        $why = match ($this) {
            self::New => 'is new',
            self::Renamed => 'was renamed',
            self::Unread => 'cannot be read as it was and as it is',
            self::Shared => 'changed code its tests share',
            self::Dependent => 'changed a test that its other tests may depend on',
        };

        return Reason::that(sprintf(self::REACHED, $file, $why, $files));
    }
}
