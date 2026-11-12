<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function max;
use function min;

use NightWorksIO\MutationGate\Core\Config\Learned;
use NightWorksIO\MutationGate\Core\Mutant\RunnerMutatorName;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * What a mutant's limit is kept between (ADR-0008): never less
 * than the floor, `timeouts.seconds`, and never more than the most,
 * `timeouts.most`; the mutators whose silence limit has a lower floor,
 * `timeouts.tighter`; how long a run of no test took to start on this
 * machine, where the run measured it, which the limit is laid on; the
 * multiple of its covering tests' own time it allows, `timeouts.multiple`;
 * and what each test of a run is held to on its own, `timeouts.perTest` and
 * `timeouts.testFloor` (ADR-0090).
 * A learned floor is the standard multiple of that start-up, at least a
 * second, and the floor written where no start-up was measured; a learned
 * multiple is the one the ledger's kills give, and the standard where it
 * gives none.
 */
final readonly class LimitBounds
{
    /** The least a learned floor allows, in seconds. */
    private const float LEAST_LEARNED_FLOOR = 1.0;

    private function __construct(
        private Seconds $floor,
        private Seconds $most,
        private TighterSilence $tighter,
        private Seconds|Unmeasured $startUp,
        private Multiple|Learned $multiple,
        private Seconds|Learned $learnedFloor,
        private PerTestLimit $perTest,
        private float $unmeasuredStartUp,
        private float $startUpMultiple,
    ) {
    }

    /**
     * Between these, a run being allowed this many seconds to start in where
     * nothing measured its start-up, and this many times its measured
     * start-up where something did.
     */
    public static function between(
        Seconds $floor,
        Seconds $most,
        float $unmeasuredStartUp = MutantLimit::START_UP,
        float $startUpMultiple = MutantLimit::FACTOR,
    ): self {
        return new self(
            $floor,
            $most,
            TighterSilence::none(),
            Unmeasured::duration(),
            Multiple::standard(),
            Seconds::of(0.0),
            PerTestLimit::off(),
            $unmeasuredStartUp,
            $startUpMultiple,
        );
    }

    /** These bounds, the silence limit of these mutators kept above a lower floor. */
    public function tighterFor(TighterSilence $tighter): self
    {
        return new self(
            $this->floor,
            $this->most,
            $tighter,
            $this->startUp,
            $this->multiple,
            $this->learnedFloor,
            $this->perTest,
            $this->unmeasuredStartUp,
            $this->startUpMultiple,
        );
    }

    /** These bounds, a run of no test having taken this long to start, or nothing having measured one. */
    public function startingIn(Seconds|Unmeasured $startUp): self
    {
        return new self(
            $this->floor,
            $this->most,
            $this->tighter,
            $startUp,
            $this->multiple,
            $this->learnedFloor,
            $this->perTest,
            $this->unmeasuredStartUp,
            $this->startUpMultiple,
        );
    }

    /** These bounds, their floor learned from the start-up, the written floor where none was measured. */
    public function learningFloor(): self
    {
        return new self(
            $this->floor,
            $this->most,
            $this->tighter,
            $this->startUp,
            $this->multiple,
            Learned::Learned,
            $this->perTest,
            $this->unmeasuredStartUp,
            $this->startUpMultiple,
        );
    }

    /** These bounds, allowing this multiple of the covering tests' own time, or the one the ledger's kills give. */
    public function multipliedBy(Multiple|Learned $multiple): self
    {
        return new self(
            $this->floor,
            $this->most,
            $this->tighter,
            $this->startUp,
            $multiple,
            $this->learnedFloor,
            $this->perTest,
            $this->unmeasuredStartUp,
            $this->startUpMultiple,
        );
    }

    /** These bounds, a learned multiple being this one; a written multiple stays as it is. */
    public function learning(Multiple $multiple): self
    {
        return $this->multiple instanceof Learned ? $this->multipliedBy($multiple) : $this;
    }

    /** How many times its covering tests' own time a mutant is allowed; the standard where it is learned, not known. */
    public function multiple(): Multiple
    {
        return $this->multiple instanceof Learned ? Multiple::standard() : $this->multiple;
    }

    /** Whether the multiple is the one the ledger's kills give, not one the config writes. */
    public function learnsMultiple(): bool
    {
        return $this->multiple instanceof Learned;
    }

    /** Whether the floor is learned from the start-up, not the one the config writes. */
    public function learnsFloor(): bool
    {
        return $this->learnedFloor instanceof Learned;
    }

    /** These bounds, each test of a run held to this limit of its own (ADR-0090). */
    public function watchingEachTest(PerTestLimit $perTest): self
    {
        return new self(
            $this->floor,
            $this->most,
            $this->tighter,
            $this->startUp,
            $this->multiple,
            $this->learnedFloor,
            $perTest,
            $this->unmeasuredStartUp,
            $this->startUpMultiple,
        );
    }

    /** What each test of a run is held to on its own: `timeouts.perTest` and `timeouts.testFloor`. */
    public function perTest(): PerTestLimit
    {
        return $this->perTest;
    }

    /** How long a run of no test took to start on this machine, or that nothing measured it. */
    public function startUp(): Seconds|Unmeasured
    {
        return $this->startUp;
    }

    /** What a run is allowed to start in where nothing measured its start-up: `timeouts.startUp`. */
    public function unmeasuredStartUp(): Seconds
    {
        return Seconds::of($this->unmeasuredStartUp);
    }

    /**
     * `timeouts.startUpMultiple`: how many times a run of no test's measured start-up a mutant's run allows to start.
     */
    public function startUpMultiple(): float
    {
        return $this->startUpMultiple;
    }

    /**
     * What the silence limit of a mutant of this mutator, as a runner names
     * it, is kept between: these bounds, with the lower floor where the
     * mutator is one `timeouts.tighter` lists.
     */
    public function silenceOf(RunnerMutatorName $mutator): self
    {
        return self::between($this->tighter->floorOf($mutator, $this->floor()), $this->most)
            ->tighterFor($this->tighter)
            ->startingIn($this->startUp)
            ->multipliedBy($this->multiple)
            ->watchingEachTest($this->perTest);
    }

    /** The mutators whose silence limit has a lower floor. */
    public function tighter(): TighterSilence
    {
        return $this->tighter;
    }

    /**
     * `timeouts.seconds`: the least any mutant is allowed, and what one whose
     * tests are not timed is; learned, `timeouts.startUpMultiple` times the
     * measured start-up, at least a second and at most the most.
     */
    public function floor(): Seconds
    {
        return $this->learnedFloor instanceof Learned && $this->startUp instanceof Seconds
            ? Seconds::of(min(
                max(self::LEAST_LEARNED_FLOOR, $this->startUpMultiple * $this->startUp->seconds()),
                $this->most->seconds(),
            ))
            : $this->floor;
    }

    /** `timeouts.most`: the most any mutant is allowed. */
    public function most(): Seconds
    {
        return $this->most;
    }

    /** These bounds with another most, as a run again asks for. */
    public function upToInstead(Seconds $most): self
    {
        return new self(
            $this->floor,
            $most,
            $this->tighter,
            $this->startUp,
            $this->multiple,
            $this->learnedFloor,
            $this->perTest,
            $this->unmeasuredStartUp,
            $this->startUpMultiple,
        );
    }

    /** These seconds, raised to the floor and lowered to the most. */
    public function kept(Seconds $seconds): Seconds
    {
        return Seconds::of(min(max($this->floor()->seconds(), $seconds->seconds()), $this->most->seconds()));
    }
}
