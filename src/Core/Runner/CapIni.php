<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function sprintf;

/**
 * The ini file a run's memory cap is written as (ADR-0004, decision 9): the
 * cap, and, for a runner that reads a mutant's fatal error from its standard
 * output alone, `display_errors` set to print there. That setting is inert:
 * it decides where an error is shown, never what a test computes.
 */
final readonly class CapIni
{
    private function __construct(private MemoryCap $cap, private bool $showsErrors)
    {
    }

    public static function of(MemoryCap $cap): self
    {
        return new self($cap, showsErrors: false);
    }

    /** This ini, printing each error on the standard output. */
    public function showingErrors(): self
    {
        return new self($this->cap, showsErrors: true);
    }

    /** The file's text. */
    public function text(): string
    {
        return $this->showsErrors
            ? sprintf("%s%s=%s\n", $this->cap->ini(), InertSetting::DisplayErrors->value, DisplayWord::Stdout->value)
            : $this->cap->ini();
    }
}
