<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use DateTimeImmutable;

use function implode;

use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Time\Day;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function sprintf;

/** Ignores that have expired, or expire within 14 days (ADR-0008, decision 4). */
final readonly class IgnoresExpiring
{
    /** How many days ahead an ignore's end is said, as the PR comment names it in advance. */
    private const int NOTICE = 14;

    private const string ENTRY = 'ignores.entries[%d] %s %s';

    private const string WHY
        = 'An ignore lasts only as long as its reason, and once it expires its mutants count again.';

    private const string FIX
        = 'Check each reason again: where it holds, move expires later; where a test now tells, remove the entry.';

    public static function in(Observations $observed): Findings
    {
        $settings = $observed->settings();
        $now = $observed->now();

        if (! $settings instanceof Settings || ! $now instanceof DateTimeImmutable) {
            return Findings::none();
        }

        $today = Day::on($now);
        $latest = Day::on($now->modify(sprintf('+%d days', self::NOTICE)));
        $found = [];

        foreach ($settings->ignores()->entries() as $index => $entry) {
            $expires = $entry->expires();

            if (! $expires instanceof Day || $latest->isBefore($expires)) {
                continue;
            }

            $found[] = sprintf(
                self::ENTRY,
                $index,
                $expires->isBefore($today) ? 'expired on' : 'expires on',
                $expires->value(),
            );
        }

        return $found === []
            ? Findings::none()
            : Findings::of(Finding::of(
                Slug::IgnoresExpiring,
                Severity::Advice,
                sprintf('%s.', implode('; ', $found)),
                self::WHY,
                self::FIX,
            ));
    }
}
