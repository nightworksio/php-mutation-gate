<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function max;
use function min;

use NightWorksIO\MutationGate\Core\Mutant\RunnerMutatorName;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * What a mutant's limit is kept between (ADR-0008): never less
 * than the floor, `timeouts.seconds`, and never more than the most,
 * `timeouts.most`; the mutators whose silence limit has a lower floor,
 * `timeouts.tighter`; and how long a run of no test took to start on this
 * machine, where the run measured it, which the limit is laid on.
 */
final readonly class LimitBounds
{
    private function __construct(
        private Seconds $floor,
        private Seconds $most,
        private TighterSilence $tighter,
        private Seconds|Unmeasured $startUp,
        private PerTestLimit|NotGiven $perTest = new NotGiven(),
    ) {
    }

    public static function between(Seconds $floor, Seconds $most): self
    {
        return new self($floor, $most, TighterSilence::none(), Unmeasured::duration());
    }

    /** No floor, and this most: a runner's own rule that only caps, as Infection's does. */
    public static function upTo(Seconds $most): self
    {
        return new self(Seconds::of(0.0), $most, TighterSilence::none(), Unmeasured::duration());
    }

    /** These bounds, the silence limit of these mutators kept above a lower floor. */
    public function tighterFor(TighterSilence $tighter): self
    {
        return new self($this->floor, $this->most, $tighter, $this->startUp, $this->perTest);
    }

    /** These bounds, a run of no test having taken this long to start, or nothing having measured one. */
    public function startingIn(Seconds|Unmeasured $startUp): self
    {
        return new self($this->floor, $this->most, $this->tighter, $startUp, $this->perTest);
    }

    /** These bounds, each test of a run held to this limit of its own (ADR-0090). */
    public function watchingEachTest(PerTestLimit $perTest): self
    {
        return new self($this->floor, $this->most, $this->tighter, $this->startUp, $perTest);
    }

    /** What each test of a run is held to on its own: `timeouts.perTest` and `timeouts.testFloor`. */
    public function perTest(): PerTestLimit
    {
        return $this->perTest instanceof PerTestLimit ? $this->perTest : PerTestLimit::off();
    }

    /** How long a run of no test took to start on this machine, or that nothing measured it. */
    public function startUp(): Seconds|Unmeasured
    {
        return $this->startUp;
    }

    /**
     * What the silence limit of a mutant of this mutator, as a runner names
     * it, is kept between: these bounds, with the lower floor where the
     * mutator is one `timeouts.tighter` lists.
     */
    public function silenceOf(RunnerMutatorName $mutator): self
    {
        return new self(
            $this->tighter->floorOf($mutator, $this->floor),
            $this->most,
            $this->tighter,
            $this->startUp,
            $this->perTest,
        );
    }

    /** The mutators whose silence limit has a lower floor. */
    public function tighter(): TighterSilence
    {
        return $this->tighter;
    }

    /** `timeouts.seconds`: the least any mutant is allowed, and what one whose tests are not timed is. */
    public function floor(): Seconds
    {
        return $this->floor;
    }

    /** `timeouts.most`: the most any mutant is allowed. */
    public function most(): Seconds
    {
        return $this->most;
    }

    /** These bounds with another most, as a run again asks for. */
    public function upToInstead(Seconds $most): self
    {
        return new self($this->floor, $most, $this->tighter, $this->startUp, $this->perTest);
    }

    /** These seconds, raised to the floor and lowered to the most. */
    public function kept(Seconds $seconds): Seconds
    {
        return Seconds::of(min(max($this->floor->seconds(), $seconds->seconds()), $this->most->seconds()));
    }
}
