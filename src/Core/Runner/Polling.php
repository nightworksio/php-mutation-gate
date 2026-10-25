<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use Closure;

use function intdiv;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/** How often a runner's shell looks at a process it waits on to end by a deadline. */
final readonly class Polling
{
    /** The wait between looks, in seconds, short beside the shortest mutant's run: about a hundred looks a second. */
    private const float INTERVAL = 0.01;

    /** The wait for a process that has closed its pipes to be seen to end, as it does on its way out. */
    private const float MOMENT = 0.001;

    public static function interval(): Seconds
    {
        return Seconds::of(self::INTERVAL);
    }

    public static function moment(): Seconds
    {
        return Seconds::of(self::MOMENT);
    }

    /**
     * Waits until a condition holds, this long at most: it looks, and pauses
     * an interval between looks, for as many intervals as fit in the wait.
     *
     * @param Closure(): bool    $holds
     * @param Closure(int): void $pause pauses this many microseconds, as usleep does
     */
    public static function wait(Seconds $within, Closure $holds, Closure $pause): void
    {
        $step = self::interval()->microseconds();
        $looks = intdiv($within->microseconds(), $step);

        for ($look = 0; ! $holds() && $look < $looks; $look++) {
            $pause($step);
        }
    }
}
