<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Migration;

use NightWorksIO\MutationGate\Core\GateCommand;
use NightWorksIO\MutationGate\Core\Verb;

use function sprintf;

/** A step, and the version whose release made it. */
final readonly class Retired
{
    /** What a file that still writes it is told (ADR-0026). */
    private const string PENDING = '%s in %s: run `%s`';

    /** What a file is told where `migrate` cannot make the change. */
    private const string BY_HAND = '%s in %s, and migrate cannot make that change here: edit it by hand';

    private function __construct(private Step $step, private string $version, private GateCommand $gate)
    {
    }

    /** A step of this release, whose `migrate` is named as the gate was started. */
    public static function of(Step $step, Migration $in, GateCommand $gate): self
    {
        return new self($step, $in->version(), $gate);
    }

    public function step(): Step
    {
        return $this->step;
    }

    /** What a file that still writes it is told: `` `a` became `b` in 2.0.0: run `<gate> migrate` ``. */
    public function pending(): string
    {
        return sprintf(self::PENDING, $this->step->change(), $this->version, $this->gate->command(Verb::Migrate));
    }

    /** What a file is told where `migrate` cannot make the change. */
    public function byHand(): string
    {
        return sprintf(self::BY_HAND, $this->step->change(), $this->version);
    }
}
