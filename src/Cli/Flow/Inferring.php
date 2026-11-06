<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Subsumption\Inference;
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

    /**
     * The results the budget left, each mutant it left unrun saying its kill
     * is inferred where a mutant subsuming it was killed, by what either
     * ledger learned; as they are where the run infers nothing.
     */
    public function of(UnitResults $left, Ledgers $ledgers, UnitResults $fresh): UnitResults
    {
        return ! $this->adapters->environment->inCi() && $this->settings->local()->inferSubsumed()
            ? Inference::in($left, $ledgers->own()->and($ledgers->defaultBranch())->learned()->subsumption(), $fresh)
            : $left;
    }
}
