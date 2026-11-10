<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Local;

use NightWorksIO\MutationGate\Core\Config\LocalWork;

/**
 * What a run of the local loop, `watch` or `serve`, does that `run` and CI
 * do not (ADR-0120): a coverage run of one test file in one process, where
 * `local.coverageAlone` says so, and sets of warm workers no larger than
 * their runs, where `local.sizedPools` does; and, for a quick round of
 * `watch`, proofs keyed apart from every other run's (ADR-0146).
 *
 * @internal
 */
final readonly class LocalLoop
{
    private function __construct(private LocalWork $work, private bool $quick)
    {
    }

    public static function of(LocalWork $work): self
    {
        return new self($work, false);
    }

    /** The same, for a quick round of `watch` (ADR-0146). */
    public function quickly(): self
    {
        return new self($this->work, true);
    }

    /** Whether it runs a quick round, whose proofs no other run takes. */
    public function isQuick(): bool
    {
        return $this->quick;
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
