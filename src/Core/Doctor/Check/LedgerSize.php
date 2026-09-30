<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use function implode;

use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\KeptLedgers;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function sprintf;

/**
 * A ledger past the compressed limit a run reads a ledger to (ADR-0017,
 * decision 10; ADR-0013, decision 13), which no run reads.
 */
final readonly class LedgerSize
{
    /** Bytes in a megabyte, as doctor's findings count sizes. */
    public const int PER_MEGABYTE = 1_000_000;

    private const string LEDGER = '%s is %.1f MB';

    private const string FOUND = '%s, compressed.';

    private const string WHY
        = 'No run reads a ledger past %.1f MB compressed, twice one at the retention cap: this one was never trimmed.';

    private const string FIX
        = 'Delete it, or the file that is not a ledger; the next run of its scope writes its ledger afresh.';

    public static function in(Observations $observed): Findings
    {
        $ledgers = $observed->ledgers();
        $limits = LedgerLimits::standard();
        $large = [];

        foreach ($ledgers instanceof KeptLedgers ? $ledgers : [] as $ledger) {
            $said = sprintf(self::LEDGER, $ledger->file()->value(), $ledger->bytes() / self::PER_MEGABYTE);
            $large = $limits->admitsPacked($ledger->bytes()) ? $large : [...$large, $said];
        }

        return $large === [] ? Findings::none() : Findings::of(Finding::of(
            Slug::LedgerTooLarge,
            Severity::Slow,
            sprintf(self::FOUND, implode('; ', $large)),
            sprintf(self::WHY, $limits->packed() / self::PER_MEGABYTE),
            self::FIX,
        ));
    }
}
