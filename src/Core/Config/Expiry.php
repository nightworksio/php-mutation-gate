<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Core\Time\Day;

use function sprintf;

/**
 * Where an `ignores.entries` entry that ends stands against its day on the
 * day of a run (ADR-0008, decision 4): it lasts past the notice, it expires
 * within it, today included, or its day has passed and it no longer applies.
 */
enum Expiry
{
    case Lasting;
    case Expiring;
    case Expired;

    /** How many days ahead an ignore's end is named, as the PR comment names it in advance. */
    public const int NOTICE = 14;

    public static function of(Day $expires, DateTimeImmutable $now): self
    {
        return match (true) {
            $expires->isBefore(Day::on($now)) => self::Expired,
            Day::on($now->modify(sprintf('+%d days', self::NOTICE)))->isBefore($expires) => self::Lasting,
            default => self::Expiring,
        };
    }
}
