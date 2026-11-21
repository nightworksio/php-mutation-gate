<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;

/**
 * Whether a full split-stream run starts without a fresh map, and the kept
 * map it plans by where it does (ADR-0135).
 *
 * @internal
 */
final readonly class EarlyStart
{
    public function __construct(private Adapters $adapters, private Settings $settings, private Setup $setup)
    {
    }

    /**
     * The kept map a full split-stream run plans by without a fresh one,
     * where `splitStream.early` starts it early: none handed, the run full,
     * its streams split by tests, and, under `no-match`, no proof of the
     * ledgers it reads may share its first five inputs; none where it waits
     * for a fresh map, or none is kept.
     */
    public function keptMap(
        Inventory $inventory,
        Ledgers $ledgers,
        Mode $mode,
        CoverageRun|CoverageRead $coverage,
    ): CoverageMeasured|NotGiven {
        $pest = $this->settings->effective()->running()->pest();

        $splits = $pest->splitsByTests($this->settings->runner()->choice());

        if (! $coverage instanceof CoverageRun || ! $mode->isFull() || ! $splits) {
            return NotGiven::value();
        }

        $reads = Keying::readsOf(
            $this->adapters,
            $this->settings,
            $this->setup,
            LearnedLimits::of($this->settings, $ledgers)->multiple(),
        );
        $match = ! $reads instanceof Digest
            || $ledgers->own()->proofs()->mayShareReads($reads)
            || $ledgers->defaultBranch()->proofs()->mayShareReads($reads);

        return $pest->splitStream()->early()->startsEarly($mode->isUnproven(), $match)
            ? new KeptMaps($this->adapters, $this->settings)->keptAsIs($inventory)
            : NotGiven::value();
    }
}
