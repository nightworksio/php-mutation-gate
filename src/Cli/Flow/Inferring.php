<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Subsumption\Inference;
use NightWorksIO\MutationGate\Core\Subsumption\Subsumption;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;

/**
 * Where a run infers the kills of mutants its budget left unrun: outside CI,
 * under `local.inferSubsumed` (ADR-0079).
 *
 * @internal
 */
final readonly class Inferring
{
    public function __construct(private Adapters $adapters, private Settings $settings)
    {
    }

    /** What the run holds subsumed mutants back by: what either ledger learned, where it infers; nothing elsewhere. */
    public function holding(Ledgers $ledgers): Subsumption|NotGiven
    {
        return $this->infers() ? $ledgers->subsumption() : NotGiven::value();
    }

    /**
     * The results the budget left, each mutant it left unrun saying its kill
     * is inferred where a mutant subsuming it was killed, by what either
     * ledger learned; as they are where the run infers nothing.
     */
    public function of(UnitResults $left, Ledgers $ledgers, UnitResults $fresh): UnitResults
    {
        return $this->infers() ? Inference::in($left, $ledgers->subsumption(), $fresh) : $left;
    }

    private function infers(): bool
    {
        return ! $this->adapters->environment->inCi() && $this->settings->local()->inferSubsumed();
    }
}
