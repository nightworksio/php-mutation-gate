<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_map;
use function in_array;
use function is_float;
use function is_int;
use function is_string;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Sample\AutoSample;
use NightWorksIO\MutationGate\Core\Sample\Confidence;
use NightWorksIO\MutationGate\Core\Sample\SampledRun;

use function sprintf;

/**
 * How a sampled run sizes and draws its sample (ADR-0020):
 * `sampling.confidence`, `sampling.margin`, `sampling.minUnits`,
 * `sampling.seed`, and when a run samples of its own accord, `sampling.auto`
 * and `sampling.autoRuns` (ADR-0086), which the `Reach` part holds, as they
 * decide which units run, as reach does. Each judges or reports only.
 */
final readonly class Sampling
{
    /** The half-width in points each tree's interval is sized for. */
    private const float MARGIN = 2.0;

    /** The fewest units a tree's sampled stratum runs, where it holds as many. */
    private const int MIN_UNITS = 30;

    /** The kinds of run that may sample of their own accord: a pull request's alone. */
    private const array AUTO_RUNS = [SampledRun::PullRequests];

    /** @param Listed<SampledRun>|Absent $autoRuns */
    private function __construct(
        private Confidence|Absent $confidence,
        private int|float|Absent $margin,
        private int|Absent $minUnits,
        private string|Absent $seed,
        private AutoSample|Absent $auto,
        private Listed|Absent $autoRuns,
    ) {
    }

    /** @param Listed<SampledRun>|Absent $autoRuns */
    public static function of(
        Confidence|Absent $confidence = new Absent(),
        int|float|Absent $margin = new Absent(),
        int|Absent $minUnits = new Absent(),
        string|Absent $seed = new Absent(),
        AutoSample|Absent $auto = new Absent(),
        Listed|Absent $autoRuns = new Absent(),
    ): self {
        return new self($confidence, $margin, $minUnits, $seed, $auto, $autoRuns);
    }

    /** This, with what a later layer sets laid over it, key by key. */
    public function over(self $later): self
    {
        return new self(
            Absent::laid($this->confidence, $later->confidence),
            Absent::laid($this->margin, $later->margin),
            Absent::laid($this->minUnits, $later->minUnits),
            Absent::laid($this->seed, $later->seed),
            Absent::laid($this->auto, $later->auto),
            Absent::laid($this->autoRuns, $later->autoRuns),
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

    /** `sampling.auto`: when a run samples of its own accord (ADR-0086); never by default. */
    public function auto(): AutoSample
    {
        return $this->auto instanceof AutoSample ? $this->auto : AutoSample::off();
    }

    /** `sampling.autoRuns`: whether a run of this kind may sample by itself; a pull request's alone by default. */
    public function samplesOfItself(SampledRun $run): bool
    {
        return in_array($run, [...$this->autoRuns()], strict: true);
    }

    /**
     * `sampling.autoRuns`: the kinds of run that may sample by themselves; a pull request's alone by default.
     *
     * @return Listed<SampledRun>
     */
    public function autoRuns(): Listed
    {
        return $this->autoRuns instanceof Listed ? $this->autoRuns : Listed::of(...self::AUTO_RUNS);
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
                Member::of('auto', $this->auto instanceof AutoSample ? $this->auto->written() : $this->auto),
                Member::of(
                    'autoRuns',
                    $this->autoRuns instanceof Listed ? Json::items(...$this->runNames()) : $this->autoRuns,
                ),
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
            ...$this->autoCalls(),
        ]);
    }

    /** @return list<string> each kind of run `sampling.autoRuns` lists, by its name */
    private function runNames(): array
    {
        return array_map(static fn(SampledRun $run): string => $run->value, [...$this->autoRuns()]);
    }

    /** @return list<string> the builder's calls for `sampling.auto` and `sampling.autoRuns`, where they are set */
    private function autoCalls(): array
    {
        $runs = $this->autoRuns instanceof Listed
            ? [sprintf('Sampling::autoRuns(%s)', PhpCalls::literals(...$this->runNames()))]
            : [];

        return match (true) {
            ! $this->auto instanceof AutoSample => $runs,
            $this->auto->isOff() => ['Sampling::autoOff()', ...$runs],
            default => [sprintf('Sampling::auto(%s)', PhpCalls::literal($this->auto->written())), ...$runs],
        };
    }
}
