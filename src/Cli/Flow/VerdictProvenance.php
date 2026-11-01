<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\JudgedCommit;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Verdict\Provenance;

/**
 * Where a verdict comes from, as the verdict's flow reads it: this gate, the
 * commit the plan judged, where git can name it, the runner, where it can
 * say which it is, and the baseline file the floors are read from.
 *
 * @internal
 */
final readonly class VerdictProvenance
{
    public static function of(
        Adapters $adapters,
        Setup $setup,
        Settings $settings,
        JudgedCommit|CannotTell $judged,
    ): Provenance {
        $runner = $adapters->runner->identity($adapters->withheld);
        $provenance = Provenance::of($setup->gate, $settings->floors()->baseline())->startedAs($setup->command);
        $provenance = $judged instanceof JudgedCommit ? $provenance->judging($judged->commit()) : $provenance;

        return $runner instanceof Identity ? $provenance->ranBy($runner) : $provenance;
    }
}
