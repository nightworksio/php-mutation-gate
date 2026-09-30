<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Runner\Withheld;

/**
 * The runner a config chooses, and the environment variables it adds to
 * those the runner withholds from the project's tests (ADR-0004).
 */
final readonly class ChosenRunner
{
    private function __construct(private Choice $choice, private Withheld $withhold)
    {
    }

    public static function of(Choice $choice, Withheld $withhold): self
    {
        return new self($choice, $withhold);
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
}
