<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Briefing;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;

/**
 * What a run reads its coverage map from, the line that says how it was
 * measured, where one does, and whether it was measured against the map its
 * own scope keeps, which its own code could have written (ADR-0023, decision
 * 2), so its verdict counts as one that used its own scope (ADR-0007,
 * decision 3).
 */
final readonly class CoverageMeasured
{
    private function __construct(
        private CoverageRun|CoverageRead $request,
        private string|NotGiven $said,
        private bool $ownScope,
    ) {
    }

    public static function of(CoverageRun|CoverageRead $request, string|NotGiven $said): self
    {
        return new self($request, $said, ownScope: false);
    }

    /** What a run asked for, as it is: no kept map is measured against. */
    public static function asked(CoverageRun|CoverageRead $request): self
    {
        return new self($request, NotGiven::value(), ownScope: false);
    }

    /** This, measured against the map the run's own scope keeps. */
    public function fromOwnScope(): self
    {
        return new self($this->request, $this->said, ownScope: true);
    }

    public function isFromOwnScope(): bool
    {
        return $this->ownScope;
    }

    /** A plan's briefing, saying the run was measured against its own scope's map where it was. */
    public function briefing(Briefing $briefing): Briefing
    {
        return $this->ownScope ? $briefing->onOwnScopeCoverage() : $briefing;
    }

    public function request(): CoverageRun|CoverageRead
    {
        return $this->request;
    }

    public function said(): string|NotGiven
    {
        return $this->said;
    }
}
