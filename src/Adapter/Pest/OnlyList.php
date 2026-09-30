<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_flip;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_file;
use function is_string;
use function sprintf;
use function trim;

/**
 * The native ids of the only mutants a patched run makes, one to a line in a
 * file beside the results: the gate writes it, and the patched
 * pest-plugin-mutate reads it once, in Pest's own process, before it makes a
 * mutant. A file, because one environment string holds at most 128 KiB on
 * Linux, which a list of some 7,700 ids passes.
 */
final readonly class OnlyList
{
    private const string LINE = "\n";

    /** Where a run's list is written, beside its results. */
    public static function beside(string $results): string
    {
        return sprintf('%s.only', $results);
    }

    /**
     * Writes the ids to the file, one to a line, and names it. Where it
     * cannot be written, the patched run finds no list and makes every mutant
     * of its files and mutators, as an unpatched one does, and the gate
     * matches back the ones it asked for.
     */
    public static function write(string $file, string ...$nativeIds): string
    {
        file_put_contents($file, implode(self::LINE, $nativeIds));

        return $file;
    }

    /**
     * The ids a file lists, as keys for a lookup that takes no longer as the
     * list grows, where PHP keys an id of digits alone as the number; none
     * where no file is named or it cannot be read, so the run makes every
     * mutant of its files and mutators.
     *
     * @return array<int|string, int> each id's place in the list, by the id
     */
    public static function in(string $file): array
    {
        $listed = $file !== '' && is_file($file) ? file_get_contents($file) : false;
        $text = is_string($listed) ? trim($listed) : '';

        return $text === '' ? [] : array_flip(explode(self::LINE, $text));
    }
}
