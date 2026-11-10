<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_keys;
use function array_values;

use const FILTER_VALIDATE_BOOL;

use function filter_var;
use function function_exists;
use function getenv;
use function ini_get;
use function is_array;
use function is_int;
use function is_string;
use function outbreak_lines_start;
use function outbreak_lines_stop;
use function pcov\clear;
use function pcov\collect;
use function pcov\start;
use function pcov\stop;
use function sort;

/**
 * The lines a stream's parent executes in one test, recorded by
 * `outbreak.so` where the stream observes `lines` (ADR-0147), and collected
 * by pcov where it is loaded otherwise, for a run that plans without a fresh
 * map (ADR-0135) or a quick round of `watch`: the streams record what the
 * coverage run would have measured. A run that stands in for the suite's
 * coverage report never observes `lines`, so pcov reports its executable
 * lines.
 *
 * @internal
 */
final readonly class Recorded
{
    /** The variable pcov reads before its `pcov.enabled`. */
    public const string ENABLED = 'PCOV_ENABLED';

    /** pcov's mark of a line a test executed. */
    private const int EXECUTED = 1;

    /**
     * Whether pcov is loaded and enabled, so a test's lines can be collected:
     * `PCOV_ENABLED` wins over `pcov.enabled`, as pcov reads them.
     */
    public static function usable(): bool
    {
        $variable = getenv(self::ENABLED);
        $enabled = is_string($variable) ? $variable : ini_get('pcov.enabled');

        return function_exists('pcov\\start') && filter_var($enabled, FILTER_VALIDATE_BOOL);
    }

    /** Starts recording a test's lines afresh: what records them, or false where nothing can. */
    public static function start(): LineRecorder|false
    {
        if (function_exists('outbreak_lines_start') && outbreak_lines_start()) {
            return LineRecorder::Outbreak;
        }

        if (! self::usable()) {
            return false;
        }

        clear();
        start();

        return LineRecorder::Pcov;
    }

    /**
     * Stops recording: the lines of each file the test executed, and, where
     * pcov collected them, every line it reports in each file it loaded that
     * is not known yet, executed or not, which a coverage report counts as
     * executable.
     *
     * @param  array<string, list<int>>                                 $known
     * @return array{array<string, list<int>>, array<string, list<int>>}
     */
    public static function stop(LineRecorder $recorder, array $known = []): array
    {
        if ($recorder === LineRecorder::Outbreak) {
            return [function_exists('outbreak_lines_stop') ? outbreak_lines_stop() : [], []];
        }

        if (! function_exists('pcov\\stop') || ! function_exists('pcov\\collect')) {
            return [[], []];
        }

        stop();
        $collected = collect();
        $executed = [];
        $reported = [];

        foreach (is_array($collected) ? $collected : [] as $file => $marks) {
            $marks = is_array($marks) ? $marks : [];
            $lines = self::executedOf($marks);

            if ($lines !== []) {
                $executed[(string) $file] = $lines;
            }

            if (! isset($known[(string) $file])) {
                $reported[(string) $file] = self::reportedOf($marks);
            }
        }

        return [$executed, $reported];
    }

    /**
     * @param  array<mixed> $marks
     * @return list<int>
     */
    private static function executedOf(array $marks): array
    {
        $lines = [];

        foreach ($marks as $line => $mark) {
            if (is_int($line) && $mark === self::EXECUTED) {
                $lines[] = $line;
            }
        }

        sort($lines);

        return array_values($lines);
    }

    /**
     * @param  array<mixed> $marks
     * @return list<int>
     */
    private static function reportedOf(array $marks): array
    {
        $lines = [];

        foreach (array_keys($marks) as $line) {
            if (is_int($line)) {
                $lines[] = $line;
            }
        }

        sort($lines);

        return array_values($lines);
    }
}
