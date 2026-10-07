<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function max;
use function min;

use NightWorksIO\MutationGate\Core\Mutant\RunnerMutatorName;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * What a mutant's limit is kept between (ADR-0008, decision 2): never less
 * than the floor, `timeouts.seconds`, and never more than the most,
 * `timeouts.most`; and the mutators whose silence limit has a lower floor,
 * `timeouts.tighter`.
 */
final readonly class LimitBounds
{
    private function __construct(private Seconds $floor, private Seconds $most, private TighterSilence $tighter)
    {
    }

    public static function between(Seconds $floor, Seconds $most): self
    {
        return new self($floor, $most, TighterSilence::none());
    }

    /** No floor, and this most: a runner's own rule that only caps, as Infection's does. */
    public static function upTo(Seconds $most): self
    {
        return new self(Seconds::of(0.0), $most, TighterSilence::none());
    }

    /** These bounds, the silence limit of these mutators kept above a lower floor. */
    public function tighterFor(TighterSilence $tighter): self
    {
        return new self($this->floor, $this->most, $tighter);
    }

    /**
     * What the silence limit of a mutant of this mutator, as a runner names
     * it, is kept between: these bounds, with the lower floor where the
     * mutator is one `timeouts.tighter` lists.
     */
    public function silenceOf(RunnerMutatorName $mutator): self
    {
        return new self($this->tighter->floorOf($mutator, $this->floor), $this->most, $this->tighter);
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
        return new self($this->floor, $most, $this->tighter);
    }

    /** These seconds, raised to the floor and lowered to the most. */
    public function kept(Seconds $seconds): Seconds
    {
        return Seconds::of(min(max($this->floor->seconds(), $seconds->seconds()), $this->most->seconds()));
    }
}
