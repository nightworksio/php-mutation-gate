<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function exec;
use function hrtime;
use function implode;

use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;
use function str_starts_with;
use function trim;

/** Whether a process a test left behind has ended, as the process table lists it. */
final class Ended
{
    /** How long a test waits for such a process to end: a third of the thirty seconds each one sleeps. */
    private const float WAIT_SECONDS = 10.0;

    /**
     * Whether the process with this id has ended: no longer listed, or listed
     * only as a zombie its new parent has yet to reap. It asks the process
     * table again until it has, for up to that wait, which gives a loaded
     * system time to end and reap a process the gate killed.
     */
    public static function process(int $pid): bool
    {
        $until = hrtime(as_number: true) + Seconds::of(self::WAIT_SECONDS)->nanoseconds();

        do {
            $listed = [];
            exec(sprintf('ps -o stat= -p %d', $pid), $listed);
            $state = trim(implode('', $listed));

            if ($state === '' || str_starts_with($state, 'Z')) {
                return true;
            }
        } while (hrtime(as_number: true) < $until);

        return false;
    }
}
