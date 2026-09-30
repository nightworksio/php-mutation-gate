<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Check;

use function intdiv;

use NightWorksIO\MutationGate\Core\Doctor\DoctorRun;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\Format\Bytes;
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
    private const string FOUND = 'The gate\'s own PHP has a memory_limit of %dM, and could not be given more.';

    private const string WHY = 'A run over ledgers as large as a run reads can take %dM, and PHP stops it past that.';

    private const string FIX = 'Set memory_limit to %dM or more, or to -1, for the PHP that runs the gate.';

    public static function in(Observations $observed): Findings
    {
        $run = $observed->run();
        $needed = LedgerMemory::standard();

        return ! $run instanceof DoctorRun || $needed->admits($run->memoryLimit())
            ? Findings::none()
            : Findings::of(Finding::of(
                Slug::MemoryLimitLow,
                Severity::WillFail,
                sprintf(self::FOUND, intdiv($run->memoryLimit(), Bytes::PER_MEBIBYTE)),
                sprintf(self::WHY, $needed->mebibytes()),
                sprintf(self::FIX, $needed->mebibytes()),
            ));
    }
}
