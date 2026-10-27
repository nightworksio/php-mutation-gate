<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

/**
 * The sets a verdict judges beside its trees: the new-code sets of a
 * change-scoped run (ADR-0003) and each package's security set (ADR-0021),
 * each against a floor of its own, the owner groups of every mutant, and
 * each owner held to a floor of its own (ADR-0022).
 */
final readonly class HeldSets
{
    private function __construct(
        private NewCodeVerdicts $newCode,
        private SecurityVerdicts $security,
        private OwnerVerdicts $owners,
        private OwnerFloorVerdicts $ownerFloors,
    ) {
    }

    public static function none(): self
    {
        return new self(
            NewCodeVerdicts::none(),
            SecurityVerdicts::none(),
            OwnerVerdicts::none(),
            OwnerFloorVerdicts::none(),
        );
    }

    public static function of(NewCodeVerdicts $newCode, SecurityVerdicts $security): self
    {
        return new self($newCode, $security, OwnerVerdicts::none(), OwnerFloorVerdicts::none());
    }

    /** The new-code sets alone, with no security set. */
    public static function newCodeOnly(NewCodeVerdicts $newCode): self
    {
        return new self($newCode, SecurityVerdicts::none(), OwnerVerdicts::none(), OwnerFloorVerdicts::none());
    }

    /** These sets, with every mutant grouped by the owners of its file. */
    public function owned(OwnerVerdicts $owners): self
    {
        return clone($this, ['owners' => $owners]);
    }

    /** These sets, with each owner `owners.floors` holds judged against its floor. */
    public function floored(OwnerFloorVerdicts $ownerFloors): self
    {
        return clone($this, ['ownerFloors' => $ownerFloors]);
    }

    /** The new-code sets; none where the run was not change-scoped. */
    public function newCode(): NewCodeVerdicts
    {
        return $this->newCode;
    }

    /** Each package's security set; none where no mutator makes security mutants. */
    public function security(): SecurityVerdicts
    {
        return $this->security;
    }

    /** Every mutant by the owners of its file; none where the run read no CODEOWNERS. */
    public function owners(): OwnerVerdicts
    {
        return $this->owners;
    }

    /** Each owner held to a floor of its own; none where `owners.floors` holds none. */
    public function ownerFloors(): OwnerFloorVerdicts
    {
        return $this->ownerFloors;
    }
}
