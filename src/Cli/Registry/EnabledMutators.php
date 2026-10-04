<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Registry;

use function in_array;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Definition\Nearest;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Mutators;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;
use NightWorksIO\MutationGate\Mutator\MutatorSet;

use function sprintf;

/**
 * The registered mutators a config turns on (ADR-0021): those of each set
 * `mutators.sets` names, less the ones `mutators.except` turns off. A set
 * nobody registered, or a mutator to turn off that none of those sets holds,
 * cannot be judged, and says which name was most likely meant. A runner
 * that makes its own mutants takes these beside its own, and the gate's own
 * engine beside the `default` set's (ADR-0023, decision 8).
 */
final readonly class EnabledMutators
{
    private const string UNHELD = 'mutators.except names "%s", which no mutator set in mutators.sets holds.';

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

        $turnedOn = MutatorSet::of(...$classes);
        $unheld = self::unheld($turnedOn, $config->except());
        $default = $lookup->mutatorSet(MutatorSet::defaultName());

        return $unheld instanceof CannotJudge ? $unheld : new self(
            $turnedOn,
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

    /**
     * Why a mutator `mutators.except` names cannot be turned off, where a
     * set the config turns on holds none of that name: it says the name, and
     * the name it most likely meant.
     *
     * @param Listed<string> $except
     */
    private static function unheld(MutatorSet $turnedOn, Listed $except): CannotJudge|NotGiven
    {
        $held = [];

        foreach (Enabled::of($turnedOn) as $mutator) {
            $held[] = $mutator->name()->value();
        }

        foreach ($except as $name) {
            if (! in_array($name, $held, strict: true)) {
                return CannotJudge::because(Nearest::suggested(sprintf(self::UNHELD, $name), $name, $held));
            }
        }

        return NotGiven::value();
    }
}
