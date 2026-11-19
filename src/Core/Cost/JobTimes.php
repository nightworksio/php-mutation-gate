<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * What a CI job measured of its own time around its gate (ADR-0196): the
 * platform its runner ran on, as a digest of how its PHP describes itself,
 * the setup before the gate started, where the job said when it began, and
 * how long the gate went on for past its stop, where it stopped, and how
 * many times the wall seconds of forking every mutant it streamed at each
 * covering test its second passes took, where any ended; none of it where
 * it measured none.
 */
final readonly class JobTimes
{
    private function __construct(
        private string $platform,
        private Seconds|NotGiven $setup,
        private Seconds|NotGiven $windDown,
        private float|NotGiven $passTwo,
    ) {
    }

    /** How long the job took to start its gate; none where the job did not say when it began. */
    public function setup(): Seconds|NotGiven
    {
        return $this->setup;
    }

    /** A job that measured nothing of its own time. */
    public static function none(): self
    {
        return new self('', NotGiven::value(), NotGiven::value(), NotGiven::value());
    }

    public static function of(
        string $platform,
        Seconds|NotGiven $setup,
        Seconds|NotGiven $windDown,
        float|NotGiven $passTwo,
    ): self {
        return new self($platform, $setup, $windDown, $passTwo);
    }

    /** The digest of the platform the job's runner ran on; empty where it is not known. */
    public function platform(): string
    {
        return $this->platform;
    }

    /** How long the gate went on for once it stopped; none where it never stopped. */
    public function windDown(): Seconds|NotGiven
    {
        return $this->windDown;
    }

    /** How many times their bound its second passes took; none where none ended. */
    public function passTwo(): float|NotGiven
    {
        return $this->passTwo;
    }

    /** What it teaches of jobs on its platform: all of it where it judged a share of a second wave, else no factor. */
    public function pace(bool $share): self
    {
        return $share ? $this : new self($this->platform, $this->setup, $this->windDown, NotGiven::value());
    }

    /** Whether it teaches anything of jobs on its platform. */
    public function teaches(): bool
    {
        $none = $this->setup instanceof NotGiven
            && $this->windDown instanceof NotGiven
            && $this->passTwo instanceof NotGiven;

        return $this->platform !== '' && ! $none;
    }
}
