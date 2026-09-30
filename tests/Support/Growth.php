<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_key_exists;

use Closure;

use function getrusage;
use function is_array;
use function is_int;
use function max;
use function min;

use RuntimeException;

use function sprintf;

/**
 * How a piece of work grows with its size, for the tests that keep a hot path
 * linear. The work runs at a size and at {@see SCALE} times it, and what is
 * compared is how many times as long the larger took: about four times for
 * linear work, sixteen for quadratic. No number of seconds decides anything.
 *
 * Each run is timed by the CPU time the process spent, which a busy machine
 * does not add to while the process waits its turn. The sizes run
 * alternately, {@see RUNS} times each, and the fastest run of each size
 * counts, since other work can only slow a run.
 */
final class Growth
{
    /** How many times the larger size is the smaller. */
    public const int SCALE = 4;

    /** How many times as long work at the larger size may take: linear is 4, quadratic 16. */
    public const float LINEAR = 8.0;

    private const int RUNS = 3;

    private const float MICROSECONDS = 1e6;

    /** The fields of the CPU time spent, each with how many of it make a second. */
    private const array SPENT = [
        'ru_utime.tv_sec' => 1.0,
        'ru_utime.tv_usec' => self::MICROSECONDS,
        'ru_stime.tv_sec' => 1.0,
        'ru_stime.tv_usec' => self::MICROSECONDS,
    ];

    /**
     * How many times as long the work took at {@see SCALE} times the size.
     * Each size's work is made ready untimed, once, and then timed.
     *
     * @template R
     *
     * @param Closure(int): (Closure(): R) $ready the work at a size, ready to run
     */
    public static function of(int $size, Closure $ready): float
    {
        $small = $ready($size);
        $large = $ready($size * self::SCALE);
        $fastest = [INF, INF];

        for ($run = 0; $run < self::RUNS; $run++) {
            $fastest = [min($fastest[0], self::seconds($small)), min($fastest[1], self::seconds($large))];
        }

        return $fastest[1] / max($fastest[0], 1 / self::MICROSECONDS);
    }

    /**
     * The CPU seconds a run of the work took.
     *
     * @template R
     *
     * @param Closure(): R $work
     */
    private static function seconds(Closure $work): float
    {
        $started = self::spent();
        $work();

        return self::spent() - $started;
    }

    /** The CPU seconds this process has spent, in user and system time. */
    private static function spent(): float
    {
        $usage = getrusage();
        $spent = 0.0;

        foreach (self::SPENT as $field => $per) {
            $value = is_array($usage) && array_key_exists($field, $usage) ? $usage[$field] : null;
            $spent += is_int($value) ? $value / $per : throw new RuntimeException(sprintf('getrusage() gave no %s.', $field));
        }

        return $spent;
    }
}
