<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Order;

use function basename;
use function file_put_contents;
use function is_dir;
use function json_encode;
use function mkdir;

use NightWorksIO\MutationGate\Adapter\Pest\CoverageFile;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Core\Test\TestIds;

use function sprintf;

use stdClass;

/**
 * One mutant's order, as the PHPUnit test run history Pest reads under
 * `--order-by=defects,duration-ascending`: its likely killers as defects,
 * the first ahead of the rest, and every covering test's time, so the rest
 * run fastest first. PHPUnit weighs an error above a failure and every other status the
 * same as none, so two tiers are all a history can say. It orders by file,
 * so a file with a likely killer runs first, that test first within it.
 */
final readonly class Seed
{
    /** Pest's name for the history in a cache directory. */
    public const string HISTORY = 'test-run-history';

    /** The status PHPUnit runs first: an error. */
    private const int FIRST = 8;

    /** The status it runs next: a failure. */
    private const int NEXT = 7;

    /** Where a mutant's order is kept in an order directory, by the mutated copy Pest serves it from. */
    public static function directoryOf(string $directory, string $mutated): string
    {
        return sprintf('%s/%s', $directory, basename($mutated));
    }

    /** Writes a mutant's order where its own process reads it, from the tests that cover it. */
    public static function write(
        string $seed,
        string $version,
        TestIds $killers,
        TestIds $covering,
        CoverageFile $coverage,
    ): void {
        $defects = [];
        $times = [];

        foreach ($killers as $killer) {
            $defects[HistoryId::of($killer->value())] = $defects === [] ? self::FIRST : self::NEXT;
        }

        foreach ($covering as $test) {
            $times[HistoryId::of($test->value())] = $coverage->secondsOf($test);
        }

        if (! is_dir($seed)) {
            mkdir($seed, recursive: true);
        }

        $history = [
            'version' => sprintf('pest_%s', $version),
            'defects' => $defects === [] ? new stdClass() : $defects,
            'times' => $times === [] ? new stdClass() : $times,
        ];

        file_put_contents(sprintf('%s/%s', $seed, self::HISTORY), json_encode($history, RecordLine::FLAGS));
    }
}
