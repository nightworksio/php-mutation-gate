<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_fill;

use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Job;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\WarmRun;
use NightWorksIO\MutationGate\Core\NotGiven;

/** The jobs the tests of a warm worker's claims hand it. */
final readonly class WarmClaims
{
    /** A job of this many runs, which no run starts in after the end. */
    public static function job(int $runs, float|NotGiven $end): Job
    {
        return Job::of('/p/vendor/autoload.php', NotGiven::value(), [], $end, array_fill(0, $runs, WarmRun::of([], [], 1.0, '', '', '', NotGiven::value())));
    }
}
