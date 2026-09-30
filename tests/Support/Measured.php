<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use LogicException;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/** How long a run a shell measured took; a run it did not measure fails the test. */
final readonly class Measured
{
    public static function of(Ran $ran): Seconds
    {
        $took = $ran->duration();

        if (! $took instanceof Seconds) {
            throw new LogicException('The shell measured no duration for this run.');
        }

        return $took;
    }
}
