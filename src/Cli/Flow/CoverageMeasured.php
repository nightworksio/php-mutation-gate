<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;

/** What a run reads its coverage map from, and the line that says how it was measured, where one does. */
final readonly class CoverageMeasured
{
    private function __construct(private CoverageRun|CoverageRead $request, private string|NotGiven $said)
    {
    }

    public static function of(CoverageRun|CoverageRead $request, string|NotGiven $said): self
    {
        return new self($request, $said);
    }

    /** What a run asked for, as it is: no kept map is measured against. */
    public static function asked(CoverageRun|CoverageRead $request): self
    {
        return new self($request, NotGiven::value());
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
