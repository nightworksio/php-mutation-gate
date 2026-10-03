<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Registry;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Mutators;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;
use NightWorksIO\MutationGate\Mutator\MutatorSet;

/**
 * The registered mutators a config turns on (ADR-0021): those of each set
 * `mutators.sets` names, less the ones `mutators.except` turns off. A set
 * nobody registered cannot be judged, and says which name was most likely
 * meant. A runner that makes its own mutants takes these beside its own, and
 * the gate's own engine beside the `default` set's (ADR-0023, decision 8).
 */
final readonly class EnabledMutators
{
    /** @param Listed<string> $except */
    private function __construct(private MutatorSet $turnedOn, private MutatorSet $default, private Listed $except)
    {
    }

    public static function in(Lookup $lookup, Mutators $config): self|CannotJudge
    {
        $classes = [];

        foreach ($config->sets() as $name) {
            $set = $lookup->mutatorSet($name);

            if ($set instanceof CannotJudge) {
                return $set;
            }

            $classes = [...$classes, ...$set];
        }

        $default = $lookup->mutatorSet(MutatorSet::defaultName());

        return new self(
            MutatorSet::of(...$classes),
            $default instanceof MutatorSet ? $default : MutatorSet::of(),
            $config->except(),
        );
    }

    /** The mutators the config turns on, which a runner that makes its own mutants runs beside its own. */
    public function besideTheRunners(): Enabled
    {
        return Enabled::of($this->turnedOn, ...$this->except);
    }

    /** The `default` set's mutators and those the config turns on, which the gate's own engine makes mutants with. */
    public function forTheEngine(): Enabled
    {
        return Enabled::of(MutatorSet::of(...$this->default, ...$this->turnedOn), ...$this->except);
    }
}
