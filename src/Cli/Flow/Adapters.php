<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\NoAnalyser;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\NamedMutators;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Briefing;
use NightWorksIO\MutationGate\Core\Runner\Processes;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;
use NightWorksIO\MutationGate\Port\ChangeSource;
use NightWorksIO\MutationGate\Port\CiPlan;
use NightWorksIO\MutationGate\Port\CostModel;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Port\Repository;
use NightWorksIO\MutationGate\Port\Runner;
use NightWorksIO\MutationGate\Port\StaticChecker;
use NightWorksIO\MutationGate\Port\TreeSource;

/**
 * Everything a flow asks the outside world through: the ports the config
 * chose, with the static analyser where one checks the mutants and what
 * it said it is, asked once so the keys and the checks agree, the
 * project's directory for the files no port writes, the environment the run
 * was started in, the cores of the machine it runs on, the engine that
 * counts a plan's mutants with the default set and the sets the config turns
 * on, where any is registered, the registered mutators the config turns
 * on, a warning for each set a preset offers that nothing registers, the
 * mutators whose mutants are security mutants, and the mutators a run makes
 * mutants with: every one, or the security mutators `--security` narrows it to
 * (ADR-0021).
 */
final readonly class Adapters
{
    private const string NO_SECURITY = <<<'SAID'
        --security makes mutants with the security-tagged mutators, and the config turns none on.
        Turn on the security set in mutators.sets, or a preset that offers it.
        SAID;

    public function __construct(
        public Runner $runner,
        public StaticChecker|NoAnalyser $checker,
        public AnalyserIdentity|NoAnalyser|CannotJudge $analyser,
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
        public Enabled $mutators,
        public Warnings $skippedSets,
        public NamedMutators $security,
        public Mutators $narrowedTo,
    ) {
    }

    /**
     * The same, its runs making mutants with the security mutators alone, as
     * `--security` asks (ADR-0021, decision 20); or why there are none to make
     * them with.
     */
    public function securityOnly(): self|CannotJudge
    {
        $security = [...$this->security];

        return $security === []
            ? CannotJudge::because(self::NO_SECURITY)
            : clone($this, ['narrowedTo' => Mutators::named(...$security)]);
    }

    /** A plan's briefing, saying whether its runs make mutants with the security mutators alone. */
    public function briefing(Briefing $briefing): Briefing
    {
        return $this->isSecurityOnly() ? $briefing->securityOnly() : $briefing;
    }

    /** Whether its runs make mutants with the security mutators alone. */
    public function isSecurityOnly(): bool
    {
        return ! $this->narrowedTo->isAll();
    }

    /** How many mutants the runner runs at once on this machine, as its parallelism makes of the cores. */
    public function processes(): Processes
    {
        return $this->runner->behaviour()->parallelism()->processes($this->cores);
    }
}
