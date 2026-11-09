<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Local;

use NightWorksIO\MutationGate\Core\Config\LocalWork;

/**
 * What a run of the local loop, `watch` or `serve`, does that `run` and CI
 * do not (ADR-0120): a coverage run of one test file in one process, where
 * `local.coverageAlone` says so, and sets of warm workers no larger than
 * their runs, where `local.sizedPools` does.
 *
 * @internal
 */
final readonly class LocalLoop
{
    private function __construct(private LocalWork $work)
    {
    }

    public static function of(LocalWork $work): self
    {
        return new self($work);
    }

    public function runsFileAlone(): bool
    {
        return $this->work->isOn(LocalSwitch::CoverageAlone);
    }

    public function sizesPools(): bool
    {
        return $this->work->isOn(LocalSwitch::SizedPools);
    }
}
