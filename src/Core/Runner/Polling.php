<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_pad;

use Closure;

use function intdiv;
use function max;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * How often the gate looks at a process it waits on to end by a deadline.
 *
 * @internal
 */
final readonly class Polling
{
    /** The wait between looks, in seconds, short beside the shortest mutant's run: about a hundred looks a second. */
    public const float INTERVAL = 0.01;

    /**
     * The wait for a process that has closed its pipes to be seen to end, as it does on its way out, and between
     * reads of processes that are read as they run.
     */
    private const float MOMENT = 0.001;

    public static function interval(): Seconds
    {
        return Seconds::of(self::INTERVAL);
    }

    /** The wait this process was told in `ChildVariable::Poll`: `runner.poll`, or the standard. */
    public static function told(string|false $told): Seconds
    {
        $seconds = ToldSeconds::read($told);

        return $seconds instanceof Seconds ? $seconds : self::interval();
    }

    /**
     * The wait a warm worker and its client were told, in
     * `ChildVariable::WarmWait` and `ChildVariable::Poll`: `local.childWait`
     * in the local loop, which a wake cuts short, or `runner.poll`, or the
     * standard (ADR-0120).
     */
    public static function warm(string|false $warm, string|false $poll): Seconds
    {
        $told = ToldSeconds::read($warm);

        return $told instanceof Seconds ? $told : self::told($poll);
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

        foreach (array_pad([], max(0, intdiv($within->microseconds(), $step)), $step) as $wait) {
            if ($holds()) {
                return;
            }

            $pause($wait);
        }
    }
}
