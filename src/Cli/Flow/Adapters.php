<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function implode;
use function in_array;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Opcache\Prover;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\NoAnalyser;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\NamedMutators;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Briefing;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Runner\Processes;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
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

use function sprintf;

/**
 * Everything a flow asks the outside world through: the ports the config
 * chose, with the static analyser where one checks the mutants and what
 * it said it is, asked once so the keys and the checks agree, the
 * project's directory for the files no port writes, the environment the run
 * was started in, the cores of the machine it runs on, the engine that
 * counts a plan's mutants with the default set and the sets the config turns
 * on, where any is registered, the registered mutators the config turns
 * on, a warning for each set a preset offers that nothing registers, the
 * mutators whose mutants are security mutants, the mutators a run makes
 * mutants with, every one or the security mutators `--security` narrows it
 * to (ADR-0021), the suite whose tests alone judge them, where `--suite`
 * names one (ADR-0025), and what proves a survivor equivalent (ADR-0013,
 * decision 10).
 */
final readonly class Adapters
{
    private const string NO_SECURITY = <<<'SAID'
        --security makes mutants with the security-tagged mutators, and the config turns none on.
        Turn on the security set in mutators.sets, or a preset that offers it.
        SAID;

    private const string NO_SUITE = '--suite=%s names no test suite. The PHPUnit config declares: %s.';

    private const string NO_SUITES = '--suite=%s names no test suite: the PHPUnit config declares none by name.';

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
        public Narrowing $narrowing,
        public Prover $equivalence,
    ) {
    }

    /** The same, reading and writing ledgers in this store. */
    public function withProofs(ProofStore $proofs): self
    {
        return clone($this, ['proofs' => $proofs]);
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
            : clone($this, ['narrowing' => $this->narrowing->toMutators(Mutators::named(...$security))]);
    }

    /**
     * The same, its runs' mutants judged by one suite's tests alone, as
     * `--suite` asks (ADR-0025, decision 9); or why that suite cannot judge
     * them: the PHPUnit config declares no suite of its name.
     */
    public function inSuite(SuiteName $suite): self|CannotJudge
    {
        $configured = Suite::configured($this->project);

        if ($configured instanceof CannotJudge) {
            return $configured;
        }

        $declared = [];
        $shown = [];

        foreach ($configured->suites() as $each) {
            $declared[] = $each->name();
            $shown[] = Fit::plain($each->name());
        }

        $asked = Fit::plain($suite->value());

        return match (true) {
            in_array($suite->value(), $declared, strict: true)
                => clone($this, ['narrowing' => $this->narrowing->toSuite($suite)]),
            $declared === [] => CannotJudge::because(sprintf(self::NO_SUITES, $asked)),
            default => CannotJudge::because(sprintf(self::NO_SUITE, $asked, implode(', ', $shown))),
        };
    }

    /** A plan's briefing, saying what its runs are narrowed to: the security mutators, one suite's tests, or both. */
    public function briefing(Briefing $briefing): Briefing
    {
        $suite = $this->narrowing->suite();
        $briefing = $this->isSecurityOnly() ? $briefing->securityOnly() : $briefing;

        return $suite instanceof SuiteName ? $briefing->inSuite($suite) : $briefing;
    }

    /** Whether its runs make mutants with the security mutators alone. */
    public function isSecurityOnly(): bool
    {
        return ! $this->narrowing->mutators()->isAll();
    }

    /** A coverage run, withholding what every process withholds, of the run's suite alone where it names one. */
    public function covering(CoverageRun $run): CoverageRun
    {
        $suite = $this->narrowing->suite();
        $withheld = $run->withholding($this->withheld);

        return $suite instanceof SuiteName ? $withheld->inSuite($suite) : $withheld;
    }

    /** Whether one suite's tests alone judge its runs' mutants. */
    public function isSuiteOnly(): bool
    {
        return $this->narrowing->suite() instanceof SuiteName;
    }

    /** How many mutants the runner runs at once on this machine, as its parallelism makes of the cores. */
    public function processes(): Processes
    {
        return $this->runner->behaviour()->parallelism()->processes($this->cores);
    }
}
