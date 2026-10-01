<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Processes;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;
use NightWorksIO\MutationGate\Port\ChangeSource;
use NightWorksIO\MutationGate\Port\CiPlan;
use NightWorksIO\MutationGate\Port\CostModel;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Port\Repository;
use NightWorksIO\MutationGate\Port\Runner;
use NightWorksIO\MutationGate\Port\TreeSource;

/**
 * Everything a flow asks the outside world through: the ports the config
 * chose, the project's directory for the files no port writes, the
 * environment the run was started in, the cores of the machine it runs on,
 * and the engine that counts a plan's mutants with the default set, where
 * that set is registered.
 */
final readonly class Adapters
{
    public function __construct(
        public Runner $runner,
        public TreeSource $trees,
        public ProofStore $proofs,
        public CostModel $costs,
        public CiPlan $ci,
        public ChangeSource $changes,
        public Repository $repository,
        public Directory $project,
        public Variables $environment,
        public Withheld $withheld,
        public Processes $cores,
        public Engine|NotGiven $engine,
    ) {
    }
}
