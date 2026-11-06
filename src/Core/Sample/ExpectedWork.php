<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Sample;

use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/** What a plan expects to run beyond its new code: how many mutants, and how long the runners take over them. */
final readonly class ExpectedWork
{
    private function __construct(private int $mutants, private Seconds $runnerTime)
    {
    }

    public static function of(int $mutants, Seconds $runnerTime): self
    {
        return new self($mutants, $runnerTime);
    }

    public function mutants(): int
    {
        return $this->mutants;
    }

    public function runnerTime(): Seconds
    {
        return $this->runnerTime;
    }

    /** As a plan says it: `about 1200 mutants, taking about 2h 10m of runner time`. */
    public function said(): string
    {
        return sprintf(
            'about %d %s, taking about %s of runner time',
            $this->mutants,
            $this->mutants === 1 ? 'mutant' : 'mutants',
            $this->runnerTime->text(),
        );
    }
}
