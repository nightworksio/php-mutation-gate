<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use DateInterval;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Pruning\MutatorNames;
use NightWorksIO\MutationGate\Core\Pruning\Pruned;
use NightWorksIO\MutationGate\Core\Pruning\Pruner;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Unit\Units;

use function sprintf;

/**
 * The mutators a run with a base leaves out of the units it runs whose code
 * is as their newest result recorded it (ADR-0025, decisions 1 to 3), by what
 * every ledger read learned of each mutator, carrying no result older than
 * `pruning.audit`.
 */
final readonly class PrunedUnits
{
    public function __construct(private Adapters $adapters, private Settings $settings, private Setup $setup)
    {
    }

    /** What a run since this base leaves out; nothing for a full run, which is the audit (ADR-0025, decision 3). */
    public function of(ChangeBase|CannotTell $base, Ledgers $ledgers, Units $toRun, Keying $keying): Pruned
    {
        if (! $base instanceof ChangeBase) {
            return Pruned::none();
        }

        $pruning = $this->settings->reach()->pruning();
        $audit = new DateInterval(sprintf('PT%dS', (int) $pruning->audit()->seconds()));
        $since = Instant::at($this->setup->clock->now()->sub($audit));
        $never = MutatorNames::of(...$this->adapters->security);

        return Pruner::of($pruning, $this->settings->runner()->name(), $never, $since)->pruned(
            $ledgers->own()->and($ledgers->defaultBranch())->survival(),
            $toRun,
            $ledgers->newest(),
            $keying->digestsOf($toRun),
        );
    }
}
