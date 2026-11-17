<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_key_exists;
use function getenv;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\KillerFile;
use Pest\Mutate\MutationTest;

use function sprintf;

/**
 * What Pest's own process notes of a mutant before or instead of its own
 * run (ADR-0117), kept in that process by the mutant's killer file until it
 * takes the file as that mutant's records once the mutant ends (see
 * KillerFile), ahead of what the mutant's own process wrote there; nothing
 * where the gate records no run. A shard notes most of its mutants as left
 * to another part, so none of it touches the disk.
 *
 * @internal
 */
final class Noted
{
    /** @var array<string, string> the lines noted of each mutant, by its killer file */
    private static array $lines = [];

    /** Notes these lines, each ended, of the mutant. */
    public static function of(MutationTest $test, string $lines): void
    {
        $results = getenv(GateVariable::Results->value);

        if (is_string($results) && $results !== '') {
            $file = KillerFile::beside($results, $test->mutation->modifiedSourcePath);
            $before = array_key_exists($file, self::$lines) ? self::$lines[$file] : '';
            self::$lines[$file] = sprintf('%s%s', $before, $lines);
        }
    }

    /** The lines noted for this killer file, forgotten as they are taken; none where none were. */
    public static function taken(string $file): string
    {
        $lines = array_key_exists($file, self::$lines) ? self::$lines[$file] : '';
        unset(self::$lines[$file]);

        return $lines;
    }
}
