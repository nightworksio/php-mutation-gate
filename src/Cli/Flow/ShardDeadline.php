<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

/**
 * When a shard's job stops to keep what it judged: the sooner of its budget
 * and `shards.jobLimit` (ADR-0114). A budget also starts nothing its estimate
 * says will not fit; the limit starts every unit and stops the runner.
 *
 * @internal
 */
final readonly class ShardDeadline
{
    /** The deadline of a shard starting at this instant. */
    public static function of(Settings $settings, DateTimeImmutable $now): Deadline
    {
        $budget = $settings->triage()->budget();
        $limit = $settings->shards()->jobLimit();

        $sooner = $budget instanceof Seconds && $budget->seconds() < $limit->seconds() ? $budget : $limit;

        return Deadline::after($now, $sooner);
    }

    /** What this deadline's estimates must fit: it, under a budget; nothing under the limit alone. */
    public static function fittedTo(Settings $settings, Deadline|Unlimited $deadline): Deadline|Unlimited
    {
        return $settings->triage()->budget() instanceof Seconds ? $deadline : Unlimited::time();
    }
}
