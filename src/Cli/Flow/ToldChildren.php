<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\HandoffLimits;
use NightWorksIO\MutationGate\Core\Runner\ChildVariable;
use NightWorksIO\MutationGate\Core\Runner\Polling;

use function putenv;
use function sprintf;

/**
 * What this process and those it starts read from the environment beside the settings they are handed: `runner.poll`
 * and `proofs.limits.handoff`, each only where the config sets it to something other than the standard.
 *
 * @internal
 */
final readonly class ToldChildren
{
    public static function by(Settings $settings): void
    {
        $poll = $settings->runner()->poll()->seconds();
        $kept = $settings->proofs()->retained();

        self::told(
            ChildVariable::RecordsLoads,
            $settings->reach()->lockPackages()->narrowsByLoads() ? '1' : new Absent(),
        );
        self::told(ChildVariable::Poll, $poll === Polling::INTERVAL ? new Absent() : sprintf('%F', $poll));
        self::told(
            ChildVariable::HandoffPacked,
            $kept->handoffPacked() === HandoffLimits::PACKED ? new Absent() : self::bytes($kept->handoffPacked()),
        );
        self::told(ChildVariable::HandoffUnpacked, self::bytes($kept->handoffUnpacked()));
    }

    private static function bytes(int|Absent $bytes): string|Absent
    {
        return $bytes instanceof Absent ? $bytes : sprintf('%d', $bytes);
    }

    private static function told(ChildVariable $variable, string|Absent $value): void
    {
        putenv($value instanceof Absent ? $variable->value : sprintf('%s=%s', $variable->value, $value));
    }
}
