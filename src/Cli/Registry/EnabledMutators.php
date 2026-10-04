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
use NightWorksIO\MutationGate\Core\Config\PresetSet;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Mutant\NamedMutators;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;
use NightWorksIO\MutationGate\Mutator\MutatorSet;

use function sprintf;

/**
 * The registered mutators a config turns on (ADR-0021): those of each set
 * `mutators.sets` names, less the ones `mutators.except` turns off. A set
 * only a preset offers that nobody registered is skipped with a warning
 * naming its package (ADR-0021, decision 12). Any other set nobody
 * registered, or a mutator to turn off that neither those sets nor
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

    /** A mutator only the `default` set holds, named under a runner that makes its own mutants: the name, then why. */
    private const string ONLY_DEFAULT = 'expected a mutator of a set in mutators.sets, got "%s": %s';

    private const string OWN_MUTATORS = "the %s runner runs its own mutators in place of the default set's";

    /** A set a preset offers that nothing registers: the preset, the set, and the package that registers it. */
    private const string NOT_INSTALLED
        = 'The %s preset turns on the mutator set "%s", which is not installed: `composer require --dev %s`';

    /**
     * @param Listed<string> $except
     * @param Warnings       $skipped one for each set a preset offers that nothing registers
     */
    private function __construct(
        private MutatorSet $turnedOn,
        private MutatorSet $default,
        private Listed $except,
        private Warnings $skipped,
    ) {
    }

    /** The mutators a config turns on, for the built-in runner it chooses, where it chooses one. */
    public static function in(
        Lookup $lookup,
        Mutators $config,
        BuiltinRunner|NotGiven $runner = new NotGiven(),
    ): self|CannotJudge|Invalid {
        $sets = self::sets($lookup, $config);

        if ($sets instanceof CannotJudge) {
            return $sets;
        }

        [$turnedOn, $skipped] = $sets;
        $registered = $lookup->mutatorSet(MutatorSet::defaultName());
        $default = $registered instanceof MutatorSet ? $registered : MutatorSet::of();
        $refusal = self::refusal($turnedOn, $default, $config->except(), $runner);

        return $refusal instanceof NotGiven ? new self($turnedOn, $default, $config->except(), $skipped) : $refusal;
    }

    /** A warning for each set a preset offers that nothing registers, which the run skips. */
    public function skipped(): Warnings
    {
        return $this->skipped;
    }

    /**
     * The mutators the config turns on, which a runner that makes its own
     * mutants runs beside its own, less each that stands down beside them.
     */
    public function besideTheRunners(): Enabled
    {
        return Enabled::of($this->turnedOn, ...$this->except)->besides(NamedMutators::of());
    }

    /**
     * The `default` set's mutators and those the config turns on, less each
     * that stands down beside them (ADR-0021, decision 18), which the gate's
     * own engine makes mutants with.
     */
    public function forTheEngine(): Enabled
    {
        $mutators = MutatorSet::of(...$this->default, ...$this->turnedOn);

        return Enabled::of($mutators, ...$this->except)->besides(NamedMutators::of());
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
                $why = sprintf(self::OWN_MUTATORS, $runner->value);

                return Invalid::because(Problem::at(self::EXCEPT, sprintf(self::ONLY_DEFAULT, $name, $why)));
            }
        }

        return NotGiven::value();
    }

    /**
     * The mutators of each set the config turns on, and a warning for each set
     * a preset offers that nothing registers; or why a set the config chooses
     * cannot be found.
     *
     * @return array{MutatorSet, Warnings}|CannotJudge
     */
    private static function sets(Lookup $lookup, Mutators $config): array|CannotJudge
    {
        $classes = [];
        $skipped = Warnings::none();

        foreach ($config->sets() as $name) {
            $set = $lookup->mutatorSet($name);
            $offer = $config->offering($name);

            if ($set instanceof MutatorSet) {
                $classes = [...$classes, ...$set];

                continue;
            }

            if (! $offer instanceof PresetSet) {
                return $set;
            }

            $skipped = $skipped->with(self::notInstalled($offer));
        }

        return [MutatorSet::of(...$classes), $skipped];
    }

    private static function notInstalled(PresetSet $offer): Warning
    {
        return Warning::that(sprintf(
            self::NOT_INSTALLED,
            $offer->preset()->value(),
            $offer->set()->value(),
            $offer->package(),
        ));
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
