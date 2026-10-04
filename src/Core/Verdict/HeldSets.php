<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

/**
 * The sets a verdict judges beside its trees, each against a floor of its
 * own: the new-code sets of a change-scoped run (ADR-0003, decision 8), and
 * each package's security set (ADR-0021, decision 16).
 */
final readonly class HeldSets
{
    private function __construct(private NewCodeVerdicts $newCode, private SecurityVerdicts $security)
    {
    }

    public static function none(): self
    {
        return new self(NewCodeVerdicts::none(), SecurityVerdicts::none());
    }

    public static function of(NewCodeVerdicts $newCode, SecurityVerdicts $security): self
    {
        return new self($newCode, $security);
    }

    /** The new-code sets alone, with no security set. */
    public static function newCodeOnly(NewCodeVerdicts $newCode): self
    {
        return new self($newCode, SecurityVerdicts::none());
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
}
