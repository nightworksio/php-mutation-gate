<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/**
 * Where a run's split streams record the lines each test executes, and
 * what for: a run planned without a fresh map stands in for the suite's
 * coverage report (ADR-0135), and a quick round of `watch` takes them into
 * the kept map (ADR-0147).
 */
final readonly class LineRecording
{
    private function __construct(private string $directory, private bool $standsIn)
    {
    }

    /** Nothing recorded. */
    public static function none(): self
    {
        return new self('', standsIn: false);
    }

    /** Recorded into this directory, for a run that stands in for the suite's coverage report. */
    public static function standingIn(string $directory): self
    {
        return new self($directory, standsIn: true);
    }

    /** Recorded into this directory, for the kept map of a quick round. */
    public static function forTheMap(string $directory): self
    {
        return new self($directory, standsIn: false);
    }

    /** The directory the streams record into; none where they record nothing. */
    public function directory(): string
    {
        return $this->directory;
    }

    /** Whether the streams record anything. */
    public function records(): bool
    {
        return $this->directory !== '';
    }

    /** Whether the run stands in for the suite's coverage report, which reads every executable line pcov reports. */
    public function standsIn(): bool
    {
        return $this->records() && $this->standsIn;
    }
}
