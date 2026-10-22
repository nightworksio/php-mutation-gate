<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function is_float;
use function is_int;
use function is_string;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Sample\Confidence;

use function sprintf;

/**
 * How a sampled run sizes and draws its sample (ADR-0020):
 * `sampling.confidence`, `sampling.margin`, `sampling.minUnits` and
 * `sampling.seed`, which the `Reach` part holds, as they decide which units
 * run, as reach does. Each judges or reports only.
 */
final readonly class Sampling
{
    /** The half-width in points each tree's interval is sized for. */
    private const float MARGIN = 2.0;

    /** The fewest units a tree's sampled stratum runs, where it holds as many. */
    private const int MIN_UNITS = 30;

    private function __construct(
        private Confidence|Absent $confidence,
        private int|float|Absent $margin,
        private int|Absent $minUnits,
        private string|Absent $seed,
    ) {
    }

    public static function of(
        Confidence|Absent $confidence = new Absent(),
        int|float|Absent $margin = new Absent(),
        int|Absent $minUnits = new Absent(),
        string|Absent $seed = new Absent(),
    ): self {
        return new self($confidence, $margin, $minUnits, $seed);
    }

    /** This, with what a later layer sets laid over it, key by key. */
    public function over(self $later): self
    {
        return new self(
            Absent::laid($this->confidence, $later->confidence),
            Absent::laid($this->margin, $later->margin),
            Absent::laid($this->minUnits, $later->minUnits),
            Absent::laid($this->seed, $later->seed),
        );
    }

    /** `sampling.confidence`: how sure each tree's interval is to hold its score, 95 percent by default. */
    public function confidence(): Confidence
    {
        return $this->confidence instanceof Confidence ? $this->confidence : Confidence::NinetyFive;
    }

    /** `sampling.margin`: the half-width in points each tree's interval is sized for. */
    public function margin(): float
    {
        return $this->margin instanceof Absent ? self::MARGIN : $this->margin;
    }

    /** `sampling.minUnits`: the fewest units a tree's sampled stratum runs. */
    public function minUnits(): int
    {
        return $this->minUnits instanceof Absent ? self::MIN_UNITS : $this->minUnits;
    }

    /** `sampling.seed`: what the sample is drawn from in place of the run's base or commit; not given by default. */
    public function seed(): string|NotGiven
    {
        return is_string($this->seed) ? $this->seed : NotGiven::value();
    }

    /** The `sampling` member a config writes; nothing where no key is set. */
    public function written(): Member
    {
        return Member::unlessEmpty(
            'sampling',
            Json::object(
                Member::of(
                    'confidence',
                    $this->confidence instanceof Confidence ? $this->confidence->value : $this->confidence,
                ),
                Member::of('margin', $this->margin),
                Member::of('minUnits', $this->minUnits),
                Member::of('seed', $this->seed),
            ),
        );
    }

    /** The PHP builder's calls for the keys set. */
    public function php(): PhpCalls
    {
        return PhpCalls::inWith(...[
            ...$this->confidence instanceof Confidence
                ? [sprintf('Sampling::confidence(%d)', $this->confidence->value)]
                : [],
            ...is_int($this->margin) || is_float($this->margin)
                ? [sprintf('Sampling::margin(%s)', PhpCalls::literal($this->margin))]
                : [],
            ...is_int($this->minUnits) ? [sprintf('Sampling::minUnits(%d)', $this->minUnits)] : [],
            ...is_string($this->seed) ? [sprintf('Sampling::seed(%s)', PhpCalls::literal($this->seed))] : [],
        ]);
    }
}
