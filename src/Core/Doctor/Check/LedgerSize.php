<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use function implode;

use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\KeptLedgers;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function sprintf;

/**
 * A ledger over 25 MB compressed (ADR-0017, decision 10), which every run
 * restores, decompresses and writes back before and after it mutates.
 */
final readonly class LedgerSize
{
    /** The size a ledger slows runs above, compressed; recalibrated from the benchmark. */
    private const int LIMIT = 25_000_000;

    private const int PER_MEGABYTE = 1_000_000;

    private const string LEDGER = '%s is %.1f MB';

    private const string FOUND = '%s, compressed.';

    private const string WHY
        = 'Every run restores, decompresses and writes back its scope\'s ledger, so its size is time each run spends.';

    private const string FIX
        = 'Delete the ledger of a scope that no longer runs; the next run of a live scope starts it afresh.';

    public static function in(Observations $observed): Findings
    {
        $ledgers = $observed->ledgers();
        $large = [];

        foreach ($ledgers instanceof KeptLedgers ? $ledgers : [] as $ledger) {
            $said = sprintf(self::LEDGER, $ledger->file()->value(), $ledger->bytes() / self::PER_MEGABYTE);
            $large = $ledger->bytes() > self::LIMIT ? [...$large, $said] : $large;
        }

        return $large === [] ? Findings::none() : Findings::of(Finding::of(
            Slug::LedgerSlowsRuns,
            Severity::Slow,
            sprintf(self::FOUND, implode('; ', $large)),
            self::WHY,
            self::FIX,
        ));
    }
}
