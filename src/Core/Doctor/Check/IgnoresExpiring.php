<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use function array_push;

use DateTimeImmutable;

use function implode;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Expiry;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Doctor\DoctorRun;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Time\Day;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function sprintf;

/**
 * Ignores that have expired, or expire within 14 days (ADR-0008).
 *
 * @internal
 */
final readonly class IgnoresExpiring
{
    private const string ENTRY = 'ignores.entries[%d] %s %s';

    private const string WHY
        = 'An ignore lasts only as long as its reason, and once it expires its mutants count again.';

    private const string FIX
        = 'Check each reason again: where it holds, move expires later; where a test now tells, remove the entry.';

    public static function in(Observations $observed): Findings
    {
        $settings = $observed->settings();
        $run = $observed->run();

        if (! $settings instanceof Settings || ! $run instanceof DoctorRun) {
            return Findings::none();
        }

        $found = [];

        foreach ($settings->ignores()->entries() as $index => $entry) {
            array_push($found, ...self::noted($index, $entry->expires(), $run->now()));
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

    /** @return list<string> an entry that expired or expires soon; none for one that lasts, or never expires */
    private static function noted(int $index, Day|Absent $expires, DateTimeImmutable $now): array
    {
        $expiry = $expires instanceof Day ? Expiry::of($expires, $now) : Expiry::Lasting;

        return $expiry === Expiry::Lasting ? [] : [sprintf(
            self::ENTRY,
            $index,
            $expiry === Expiry::Expired ? 'expired on' : 'expires on',
            $expires->value(),
        )];
    }
}
