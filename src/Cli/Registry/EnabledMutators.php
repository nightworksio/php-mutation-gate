<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Registry;

use function in_array;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\Definition\Nearest;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Mutators;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;
use NightWorksIO\MutationGate\Mutator\MutatorSet;

use function sprintf;

/**
 * The registered mutators a config turns on (ADR-0021): those of each set
 * `mutators.sets` names, less the ones `mutators.except` turns off. A set
 * nobody registered, or a mutator to turn off that neither those sets nor
 * the `default` set holds, cannot be judged, and says which name was most
 * likely meant. A runner that makes its own mutants takes these beside its
 * own, so a mutator to turn off that only the `default` set holds would turn
 * nothing off there, and the config is refused. The gate's own engine takes
 * them beside the `default` set's (ADR-0023, decision 8).
 */
final readonly class EnabledMutators
{
    /** Where the mutators a config turns off are written. */
    private const string EXCEPT = 'mutators.except';

    private const string UNHELD
        = 'mutators.except names "%s", which neither a mutator set in mutators.sets nor the default set holds.';

    private const string ONLY_DEFAULT
        = 'expected a mutator of a set in mutators.sets, got "%s", a default mutator the %s runner does not run';

    /** @param Listed<string> $except */
    private function __construct(private MutatorSet $turnedOn, private MutatorSet $default, private Listed $except)
    {
    }

    /** The mutators a config turns on, for the built-in runner it chooses, where it chooses one. */
    public static function in(
        Lookup $lookup,
        Mutators $config,
        BuiltinRunner|NotGiven $runner = new NotGiven(),
    ): self|CannotJudge|Invalid {
        $classes = [];

        foreach ($config->sets() as $name) {
            $set = $lookup->mutatorSet($name);

            if ($set instanceof CannotJudge) {
                return $set;
            }

            $classes = [...$classes, ...$set];
        }

        $turnedOn = MutatorSet::of(...$classes);
        $registered = $lookup->mutatorSet(MutatorSet::defaultName());
        $default = $registered instanceof MutatorSet ? $registered : MutatorSet::of();
        $refusal = self::refusal($turnedOn, $default, $config->except(), $runner);

        return $refusal instanceof NotGiven ? new self($turnedOn, $default, $config->except()) : $refusal;
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
     * Why a mutator `mutators.except` names cannot be turned off: neither a
     * set the config turns on nor the `default` set holds one of that name,
     * which says the name it most likely meant; or only the `default` set
     * does, under a runner that makes its own mutants.
     *
     * @param Listed<string> $except
     */
    private static function refusal(
        MutatorSet $turnedOn,
        MutatorSet $default,
        Listed $except,
        BuiltinRunner|NotGiven $runner,
    ): CannotJudge|Invalid|NotGiven {
        $on = self::names($turnedOn);
        $held = [...self::names($default), ...$on];
        $ownMutants = $runner instanceof BuiltinRunner && $runner->makesItsOwnMutants();

        foreach ($except as $name) {
            if (! in_array($name, $held, strict: true)) {
                return CannotJudge::because(Nearest::suggested(sprintf(self::UNHELD, $name), $name, $held));
            }

            if ($ownMutants && ! in_array($name, $on, strict: true)) {
                return Invalid::because(Problem::at(self::EXCEPT, sprintf(self::ONLY_DEFAULT, $name, $runner->value)));
            }
        }

        return NotGiven::value();
    }

    /** @return list<string> the name of each of a set's mutators */
    private static function names(MutatorSet $set): array
    {
        $names = [];

        foreach (Enabled::of($set) as $mutator) {
            $names[] = $mutator->name()->value();
        }

        return $names;
    }
}
