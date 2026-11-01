<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use Closure;

use function gc_collect_cycles;
use function gc_disable;
use function gc_enable;
use function max;
use function min;

/**
 * How a piece of work grows with its size, for the tests that keep a hot path
 * linear. The work runs at a size and at {@see SCALE} times it, and what is
 * compared is how many times as long the larger took: about eight times for
 * linear work, sixty-four for quadratic. No number of seconds decides anything.
 *
 * Each run is timed by the CPU time the process spent, which a busy machine
 * does not add to while the process waits its turn. PHP's cycle collector is
 * run before each run and held off during it: left on, it runs more often the
 * more objects a run makes, and its cost grows faster than the work. The sizes
 * run alternately, {@see RUNS} times each, and the fastest run of each size
 * counts, since other work can only slow a run.
 */
final class Growth
{
    /** How many times the larger size is the smaller. */
    public const int SCALE = 8;

    /**
     * How many times as long work at the larger size may take: linear is 8
     * and quadratic 64, and this is half of quadratic. Linear work at the
     * larger size also misses the CPU's caches more, which on a busy runner
     * takes it past 22.
     */
    public const float LINEAR = 32.0;

    private const int RUNS = 3;

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

        return $fastest[1] / max($fastest[0], 1 / CpuTime::MICROSECONDS);
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
        gc_collect_cycles();
        gc_disable();
        $started = CpuTime::spent();
        $work();
        $spent = CpuTime::spent() - $started;
        gc_enable();

        return $spent;
    }
}
