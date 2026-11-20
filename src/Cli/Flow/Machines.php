<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use Closure;

use function file_get_contents;
use function hrtime;
use function intdiv;
use function is_readable;
use function is_string;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function preg_match;
use function sort;
use function trim;

/**
 * The machine this process runs on (ADR-0159): its processor's model as
 * Linux's `/proc/cpuinfo` names it, and how long a piece of work takes on
 * it, as the median seconds of its rounds after the first, which warms it
 * up. Each timed interval holds one round and nothing else.
 *
 * @internal
 */
final readonly class Machines
{
    /** Where Linux names the processor. */
    public const string CPUINFO = '/proc/cpuinfo';

    /** How many rounds are timed, after one that is not; their median is the figure. */
    public const int ROUNDS = 5;

    private const string MODEL = '/^model name\s*:\s*(.+)$/m';

    /** The processor a file in the shape of `/proc/cpuinfo` names; not given where it names none. */
    public static function cpuIn(string $file): string|NotGiven
    {
        $text = is_readable($file) ? file_get_contents($file) : false;

        return is_string($text) && preg_match(self::MODEL, $text, $model) === 1
            ? trim($model[1])
            : NotGiven::value();
    }

    /**
     * The median seconds of a round of work on the system's clock; not given where a round failed.
     *
     * @param Closure(): bool $round one round, which says whether it succeeded
     */
    public static function timed(Closure $round): Seconds|NotGiven
    {
        return self::timedOn(static fn(): int|float => hrtime(as_number: true), $round);
    }

    /**
     * The median seconds of a round of work on a clock, the first round left
     * out of the figure; not given where a round failed.
     *
     * @param Closure(): (int|float) $clock the clock, in nanoseconds
     * @param Closure(): bool        $round one round, which says whether it succeeded
     */
    public static function timedOn(Closure $clock, Closure $round): Seconds|NotGiven
    {
        $rounds = [];

        for ($at = 0; $at <= self::ROUNDS; $at++) {
            $started = $clock();
            $succeeded = $round();
            $ended = $clock();

            if (! $succeeded) {
                return NotGiven::value();
            }

            if ($at > 0) {
                $rounds[] = ($ended - $started) / Seconds::NANOSECONDS;
            }
        }

        sort($rounds);

        return Seconds::of($rounds[intdiv(self::ROUNDS, 2)]);
    }
}
