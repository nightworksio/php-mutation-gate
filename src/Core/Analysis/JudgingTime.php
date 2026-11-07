<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Multiple;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * What judging a mutant by its tests is expected to cost, which a check
 * before them is weighed against (ADR-0077): its covering tests' own time,
 * or that time at the pace the ledgers learned kills take, as a run that
 * stops at its first killer, the likely killers first, judges a mutant in a
 * fraction of every covering test's time.
 *
 * @internal
 */
final readonly class JudgingTime
{
    private function __construct(private Multiple|NotGiven $pace)
    {
    }

    /** Every covering test's own time, summed: `staticCheck.testsTime: covering`. */
    public static function covering(): self
    {
        return new self(NotGiven::value());
    }

    /** At the pace kills were learned to take, `staticCheck.testsTime: learned`; covering where none was learned. */
    public static function atPace(Multiple|NotGiven $pace): self
    {
        return new self($pace);
    }

    /** The time judging a mutant whose covering tests take these seconds of their own is expected to take. */
    public function of(Seconds $covering): Seconds
    {
        return $this->pace instanceof Multiple ? Seconds::of($covering->seconds() * $this->pace->times()) : $covering;
    }
}
