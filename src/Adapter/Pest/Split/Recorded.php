<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_values;
use function function_exists;
use function is_array;
use function is_int;
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
    /** pcov's mark of a line a test executed. */
    private const int EXECUTED = 1;

    /** Starts collecting a test's lines afresh; false where pcov is not loaded. */
    public static function start(): bool
    {
        if (! function_exists('pcov\\start') || ! function_exists('pcov\\clear')) {
            return false;
        }

        clear();
        start();

        return true;
    }

    /**
     * Stops collecting, and the lines of each file the test executed.
     *
     * @return array<string, list<int>>
     */
    public static function stop(): array
    {
        if (! function_exists('pcov\\stop') || ! function_exists('pcov\\collect')) {
            return [];
        }

        stop();
        $collected = collect();
        $lines = [];

        foreach (is_array($collected) ? $collected : [] as $file => $marks) {
            $executed = [];

            foreach (is_array($marks) ? $marks : [] as $line => $mark) {
                if (is_int($line) && $mark === self::EXECUTED) {
                    $executed[] = $line;
                }
            }

            if ($executed !== []) {
                sort($executed);
                $lines[(string) $file] = array_values($executed);
            }
        }

        return $lines;
    }
}
