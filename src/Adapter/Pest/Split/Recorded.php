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
use function pcov\clear;
use function pcov\collect;
use function pcov\start;
use function pcov\stop;
use function sort;

/**
 * The lines a stream's parent executes in one test, collected by pcov where
 * it is loaded, for a run that plans without a fresh map (ADR-0135): the
 * streams record what the coverage run would have measured.
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

    /** Starts collecting a test's lines afresh; false where pcov cannot collect them. */
    public static function start(): bool
    {
        if (! self::usable()) {
            return false;
        }

        clear();
        start();

        return true;
    }

    /**
     * Stops collecting: the lines of each file the test executed, and every
     * line pcov reports in each file it loaded that is not known yet,
     * executed or not, which a coverage report counts as executable.
     *
     * @param  array<string, list<int>>                                 $known
     * @return array{array<string, list<int>>, array<string, list<int>>}
     */
    public static function stop(array $known = []): array
    {
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
