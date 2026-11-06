<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function clearstatcache;
use function file_exists;
use function hrtime;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/** Whether a file a process a test started writes has appeared. */
final class Appeared
{
    /** How long a test waits for such a file, which gives a loaded system time to start the process. */
    private const float WAIT_SECONDS = 10.0;

    /** Whether the file is there, looking again until it is, for up to that wait. */
    public static function file(string $path): bool
    {
        $until = hrtime(as_number: true) + Seconds::of(self::WAIT_SECONDS)->nanoseconds();

        do {
            clearstatcache();

            if (file_exists($path)) {
                return true;
            }
        } while (hrtime(as_number: true) < $until);

        return false;
    }
}
