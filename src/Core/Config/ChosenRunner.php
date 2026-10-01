<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\Withheld;

/**
 * The runner a config chooses, the environment variables it adds to those
 * the runner withholds from the project's tests, and the memory each of its
 * mutants' processes may use (ADR-0004).
 */
final readonly class ChosenRunner
{
    private function __construct(private Choice $choice, private Withheld $withhold, private MemoryCap $memory)
    {
    }

    public static function of(Choice $choice, Withheld $withhold, MemoryCap $memory): self
    {
        return new self($choice, $withhold, $memory);
    }

    public function choice(): Choice
    {
        return $this->choice;
    }

    /**
     * `runner.withhold`: the environment variables, by name or glob, a project adds to those the runner never
     * hands its tests (ADR-0004). They only ever add to the ones every run withholds.
     */
    public function withhold(): Withheld
    {
        return $this->withhold;
    }

    /** `runner.memory`: the memory each process that runs a mutant may use (ADR-0004). */
    public function memory(): MemoryCap
    {
        return $this->memory;
    }
}
