<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use NightWorksIO\MutationGate\Core\Doctor\DoctorRun;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Proof\LedgerMemory;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

use function sprintf;

/**
 * A memory_limit for the gate's own process under what its ledgers may need
 * (ADR-0013 decision 13), which the gate's command raises where PHP lets it:
 * past it, PHP stops a run over a ledger that large.
 */
final readonly class MemoryLimit
{
    private const string FOUND = 'The gate\'s own PHP may take %.0f MB of memory, and could not be given more.';

    private const string WHY
        = 'A run over ledgers as large as a run reads can take %.0f MB, and PHP stops it past that.';

    private const string FIX = 'Set memory_limit to %.0fM or more, or to -1, for the PHP that runs the gate.';

    public static function in(Observations $observed): Findings
    {
        $run = $observed->run();
        $needed = LedgerMemory::standard();

        return ! $run instanceof DoctorRun || $needed->admits($run->memoryLimit())
            ? Findings::none()
            : Findings::of(Finding::of(
                Slug::MemoryLimitLow,
                Severity::WillFail,
                sprintf(self::FOUND, $run->memoryLimit() / LedgerSize::PER_MEGABYTE),
                sprintf(self::WHY, $needed->bytes() / LedgerSize::PER_MEGABYTE),
                sprintf(self::FIX, $needed->bytes() / LedgerSize::PER_MEGABYTE),
            ));
    }
}
