<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Core\Time\Instant;

/** Instants a test names as the gate writes them, without a clock. */
final class Moment
{
    /** The instant written this way; a test that writes one wrongly gets the epoch, and its expectation fails. */
    public static function at(string $instant): Instant
    {
        $parsed = Instant::parse($instant);

        return $parsed instanceof Instant ? $parsed : Instant::at(new DateTimeImmutable('@0'));
    }
}
