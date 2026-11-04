<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Dashboard;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * One of the costliest mutators a repository's `summary.json` names (ADR-0076): its runner, its name, the
 * runner time its mutants took, how many survived, and the share killed of those judged, where any was.
 *
 * @internal
 */
final readonly class PublishedMutator
{
    private function __construct(
        private string $runner,
        private string $mutator,
        private Seconds $seconds,
        private int $survived,
        private Percentage|NotGiven $killRate,
    ) {
    }

    public static function of(
        string $runner,
        string $mutator,
        Seconds $seconds,
        int $survived,
        Percentage|NotGiven $killRate,
    ): self {
        return new self($runner, $mutator, $seconds, $survived, $killRate);
    }

    public function runner(): string
    {
        return $this->runner;
    }

    public function mutator(): string
    {
        return $this->mutator;
    }

    public function seconds(): Seconds
    {
        return $this->seconds;
    }

    public function survived(): int
    {
        return $this->survived;
    }

    public function killRate(): Percentage|NotGiven
    {
        return $this->killRate;
    }
}
