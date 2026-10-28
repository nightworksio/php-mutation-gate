<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\RunSelection;
use NightWorksIO\MutationGate\Core\Runner\StartUpVariable;
use NightWorksIO\MutationGate\Core\Runner\TighterVariables;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

use function sprintf;

/**
 * Whether the project applies `pest:patch`, the canary group a shard's
 * opening run is when it does, and which tests a mutant's own run selects.
 */
final readonly class Patching
{
    private function __construct(private Group|Unpatched $canary)
    {
    }

    public static function off(): self
    {
        return new self(Unpatched::Vendor);
    }

    public static function on(Group $canary): self
    {
        return new self($canary);
    }

    public function isOn(): bool
    {
        return $this->canary instanceof Group;
    }

    /**
     * What a run is started with to keep each mutant's limit, and its silence
     * limit, within these bounds, where the project applies the patch (see
     * MutantTime); nothing where it does not, as Pest allows each mutant its
     * own limit then.
     *
     * @return array<string, string>
     */
    public function bounding(LimitBounds $bounds): array
    {
        return $this->isOn()
            ? [
                GateVariable::MutantFloor->value => sprintf('%F', $bounds->floor()->seconds()),
                GateVariable::MutantCap->value => sprintf('%F', $bounds->most()->seconds()),
                ...TighterVariables::of($bounds->tighter()),
                ...StartUpVariable::of($bounds),
            ]
            : [];
    }

    /**
     * What a mutant's own run selects of the tests that cover it: exactly
     * those, a row of a data set alone, a class or a data set the suite holds
     * no other test of named whole, where the project applies the patch,
     * and every row of their data sets, as pest-plugin-mutate's filter
     * selects them, where it does not.
     */
    public function selecting(TestIds $covering, Suite $suite): Selection
    {
        return $this->isOn() ? Selection::exactly($covering, $suite) : Selection::of($covering);
    }

    /** Which tests of a map a mutant's own run runs, and how, as `selecting` selects them; these files hold them. */
    public function selection(TestFiles $files): RunSelection
    {
        return $this->isOn() ? new ExactRows($files) : new EveryRow();
    }

    /** The canary group, where the project applies the patch. */
    public function canary(): Group|Unpatched
    {
        return $this->canary;
    }

    /**
     * The tests a mutation run opens on: the canary group, where the run
     * opens with a map another job handed over and the project applies the
     * patch, or else the tests that judge it.
     */
    public function opensOn(WholeSuite|Group|Filter $judgedBy, bool $handedAMap): WholeSuite|Group|Filter
    {
        return $handedAMap && $this->canary instanceof Group ? $this->canary : $judgedBy;
    }
}
