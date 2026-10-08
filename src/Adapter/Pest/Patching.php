<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\StartUpVariable;
use NightWorksIO\MutationGate\Core\Runner\TighterVariables;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

use function sprintf;

/**
 * Whether the project applies `pest:patch`, and the canary group a shard's
 * opening run is when it does.
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
