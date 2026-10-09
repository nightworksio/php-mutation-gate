<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use function array_filter;
use function array_flip;
use function array_key_exists;
use function clearstatcache;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function hrtime;
use function implode;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\Runner\Polling;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function rename;
use function sprintf;
use function usleep;

/**
 * The gate's verdicts on the mutants a patched Pest planned (ADR-0020,
 * decision 12): once the plugin has written every planned mutant, it waits
 * for this file beside the results, in which the gate names the mutated copy
 * of each mutant static analysis rejected, one to a line, and none where it
 * rejected none. Pest then runs none of them: each is tested without a run,
 * and the gate reads it as killed by static analysis.
 */
final class Verdicts
{
    private const string LINE = "\n";

    /** @var array<string, int> the mutated copies the gate rejected, as keys */
    private static array $rejected = [];

    /** Where a run's verdicts are written, beside its results. */
    public static function beside(string $results): string
    {
        return sprintf('%s.verdicts', $results);
    }

    /**
     * Writes the mutated copies the gate rejected to the file, and names the
     * file. It is written whole beside, then moved in, so the plugin, which
     * reads it once it is there, never reads part of it.
     */
    public static function write(string $file, string ...$mutated): string
    {
        $part = sprintf('%s.part', $file);
        file_put_contents($part, implode(self::LINE, $mutated));
        rename($part, $file);

        return $file;
    }

    /**
     * Waits for the gate's verdicts in this file, this long at most; then
     * keeps the copies it rejected, none where it never wrote the file.
     */
    public static function await(string $file, Seconds $within): void
    {
        $until = hrtime(as_number: true) + $within->nanoseconds();

        while (! is_file($file) && hrtime(as_number: true) < $until) {
            usleep(Polling::interval()->microseconds());
            clearstatcache();
        }

        $text = is_file($file) ? file_get_contents($file) : false;
        self::$rejected = is_string($text)
            ? array_flip(array_filter(explode(self::LINE, $text), static fn(string $line): bool => $line !== ''))
            : [];
    }

    /** Whether the gate rejected the mutant that serves this mutated copy. */
    public static function rejects(string $mutated): bool
    {
        return array_key_exists($mutated, self::$rejected);
    }
}
