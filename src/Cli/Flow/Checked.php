<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_map;

use NightWorksIO\MutationGate\Core\Analysis\SurvivorChecks;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;

/**
 * What static analysis's checks of a shard's survivors came to: its mutants,
 * each survivor the analyser rejected killed by static analysis, the checks'
 * time and the survivors they left unchecked, and the survivors they took
 * up, as they were before it.
 */
final readonly class Checked
{
    public function __construct(public Mutants $mutants, public SurvivorChecks $checks, public Mutants $examined)
    {
    }

    /** The ids of the survivors the checks took up, killed, passed or left. */
    public function examinedIds(): MutantIds
    {
        $ids = array_map(static fn(Mutant $survivor): MutantId => $survivor->id(), [...$this->examined]);

        return MutantIds::of(...$ids);
    }
}
