<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Migration;

use function sprintf;

/** A step, and the version whose release made it. */
final readonly class Retired
{
    /** What a file that still writes it is told (ADR-0026, decision 1). */
    private const string PENDING = '%s in %s: run `mutation-gate migrate`';

    /** What a file is told where `migrate` cannot make the change. */
    private const string BY_HAND = '%s in %s, and migrate cannot make that change here: edit it by hand';

    private function __construct(private Step $step, private string $version)
    {
    }

    public static function of(Step $step, Migration $in): self
    {
        return new self($step, $in->version());
    }

    public function step(): Step
    {
        return $this->step;
    }

    /** What a file that still writes it is told: `` `a` became `b` in 2.0.0: run `mutation-gate migrate` ``. */
    public function pending(): string
    {
        return sprintf(self::PENDING, $this->step->change(), $this->version);
    }

    /** What a file is told where `migrate` cannot make the change. */
    public function byHand(): string
    {
        return sprintf(self::BY_HAND, $this->step->change(), $this->version);
    }
}
