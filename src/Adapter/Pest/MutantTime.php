<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function array_unique;
use function file_put_contents;
use function getenv;
use function is_numeric;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Core\Runner\MutantLimit;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * The seconds a patched Pest allows one mutant, read in Pest's parent
 * process: the standard mutant limit (ADR-0008, decision 2) of its covering
 * tests' own time, as the coverage map Pest loaded timed them, under the cap
 * the gate names. A covering test the map did not time leaves the cap.
 *
 * Each limit binds only once it is recorded, by the mutant's mutated copy,
 * in the results file the gate names, so the gate triages each timeout by
 * the limit that applied; where it cannot be recorded, or the gate names no
 * cap, the mutant keeps the limit Pest gave it.
 */
final class MutantTime
{
    /** @var array<string, float> each test's seconds, by its id, as the map Pest loaded timed it */
    private static array $timed = [];

    /**
     * Keeps each test's seconds from the coverage map Pest loaded, as
     * php-code-coverage writes one since version 14, the one the gate reads.
     * A test timed at no seconds is not timed.
     *
     * @param array{testResults?: array<string, array{time: float}>} $loaded
     */
    public static function remember(array $loaded): void
    {
        $results = array_key_exists('testResults', $loaded) ? $loaded['testResults'] : [];
        self::$timed = [];

        foreach ($results as $test => $result) {
            self::$timed += $result['time'] > 0.0 ? [$test => $result['time']] : [];
        }
    }

    /**
     * The seconds the mutant with this mutated copy is allowed, its covering
     * tests named by their ids as Pest's coverage map names them; the limit
     * Pest gave it where no cap is named or the limit cannot be recorded.
     *
     * @param list<string> $tests
     */
    public static function of(array $tests, string $mutated, int $pest): float
    {
        $cap = getenv(GateVariable::MutantCap->value);
        $results = getenv(GateVariable::Results->value);

        if (! is_string($cap) || ! is_numeric($cap) || (float) $cap <= 0.0) {
            return $pest;
        }

        $limit = MutantLimit::standard()->of(self::ownTime($tests), Seconds::of((float) $cap))->seconds();

        $recorded = $mutated !== '' && is_string($results) && $results !== ''
            && file_put_contents($results, RecordLine::limited($mutated, $limit), FILE_APPEND | LOCK_EX) !== false;

        return $recorded ? $limit : $pest;
    }

    /** @param list<string> $tests */
    private static function ownTime(array $tests): Seconds|Unmeasured
    {
        $total = 0.0;

        foreach (array_unique($tests) as $test) {
            if (! array_key_exists($test, self::$timed)) {
                return Unmeasured::duration();
            }

            $total += self::$timed[$test];
        }

        return $tests === [] ? Unmeasured::duration() : Seconds::of($total);
    }
}
