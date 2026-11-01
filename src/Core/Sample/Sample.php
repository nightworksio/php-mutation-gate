<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Sample;

use NightWorksIO\MutationGate\Core\Unit\Units;

/**
 * What a sampled run's plan drew (ADR-0020): the seed
 * the draw ranked units by, the confidence and margin it was sized for, the
 * units of each tree's sampled stratum it runs, and those it leaves out with
 * the mutants each is estimated to hold. A run that was not sampled drew
 * nothing, and judges every tree whole.
 */
final readonly class Sample
{
    private function __construct(
        private bool $drawn,
        private string $seed,
        private Confidence $confidence,
        private float $margin,
        private Units $sampled,
        private Unsampled $unsampled,
    ) {
    }

    /** No sample: every unit is judged. */
    public static function none(): self
    {
        return new self(
            drawn: false,
            seed: '',
            confidence: Confidence::NinetyFive,
            margin: 0.0,
            sampled: Units::none(),
            unsampled: Unsampled::none(),
        );
    }

    /** A sample drawn from this seed, sized for this margin in points at this confidence, holding no unit yet. */
    public static function drawn(string $seed, Confidence $confidence, float $margin): self
    {
        return new self(
            drawn: true,
            seed: $seed,
            confidence: $confidence,
            margin: $margin,
            sampled: Units::none(),
            unsampled: Unsampled::none(),
        );
    }

    /** This sample, running these units of a sampled stratum and leaving those out. */
    public function holding(Units $sampled, Unsampled $unsampled): self
    {
        return new self(
            $this->drawn,
            $this->seed,
            $this->confidence,
            $this->margin,
            $this->sampled->and($sampled),
            $this->unsampled->and($unsampled),
        );
    }

    /** Whether the run was sampled. */
    public function isDrawn(): bool
    {
        return $this->drawn;
    }

    public function seed(): string
    {
        return $this->seed;
    }

    public function confidence(): Confidence
    {
        return $this->confidence;
    }

    /** The half-width in points each tree's interval was sized for; none, 0, where no sample was drawn. */
    public function margin(): float
    {
        return $this->margin;
    }

    /** The units of the sampled strata the sample runs. */
    public function sampled(): Units
    {
        return $this->sampled;
    }

    /** The units of the sampled strata the sample leaves out, with the mutants each is estimated to hold. */
    public function unsampled(): Unsampled
    {
        return $this->unsampled;
    }

    /** These units to run, but those the sample leaves out. */
    public function running(Units $toRun): Units
    {
        $left = Units::none();

        foreach ($this->unsampled as $unsampled) {
            $left = $left->with($unsampled->unit());
        }

        return $toRun->except($left);
    }
}
