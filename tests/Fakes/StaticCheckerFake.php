<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use function array_key_exists;
use function getenv;

use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserSettings;
use NightWorksIO\MutationGate\Core\Analysis\CheckAnswers;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Analysis\MutantCheck;
use NightWorksIO\MutationGate\Core\Analysis\MutantChecks;
use NightWorksIO\MutationGate\Core\Analysis\OutOfScope;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Port\StaticChecker;

use function preg_match;
use function sprintf;

/**
 * A static analyser that answers what it was told: the configuration it runs
 * with, where told one, whether it reads a check's dependents, the
 * originals, and each mutant it knows by its file.
 * It cannot judge any other mutant.
 * Each answer stands for a process started in this process's environment
 * without what is withheld, which stops, as the contract fixture's bootstrap
 * does, where it can still see `LEAK`.
 */
final readonly class StaticCheckerFake implements StaticChecker
{
    /** The variable the contract suite sets and withholds, which the fixture's bootstrap refuses to see. */
    public const string LEAK = 'MUTATION_GATE_CONTRACT_TOKEN';

    private const string UNRESOLVED = 'The fake was told no configuration it runs with.';

    /**
     * @param array<string, Findings|OutOfScope|CannotJudge> $mutants       what it answers of each mutant, by the mutant's file
     * @param AnalyserSettings|CannotJudge|NotGiven          $configuration the configuration it says it runs with, or
     *                                                                     none it can say
     * @param bool                                           $dependents    whether it says it reads the dependents a
     *                                                                     check lists
     */
    public function __construct(
        private AnalyserIdentity|CannotJudge $identity,
        private Findings|CannotJudge $originals,
        private array $mutants,
        private AnalyserSettings|CannotJudge|NotGiven $configuration = new NotGiven(),
        private bool $dependents = false,
    ) {
    }

    /** An analyser that finds nothing in the originals and knows no mutant. */
    public static function findingNothing(): self
    {
        return new self(AnalyserIdentity::of('fake', '1.0.0', Digest::sha256Of('')), Findings::none(), []);
    }

    public function identity(Withheld $withheld): AnalyserIdentity|CannotJudge
    {
        return $this->unlessLeaked($withheld, $this->identity);
    }

    public function configuration(Withheld $withheld): AnalyserSettings|CannotJudge
    {
        $configuration = $this->configuration;

        return $this->unlessLeaked(
            $withheld,
            $configuration instanceof NotGiven ? CannotJudge::because(self::UNRESOLVED) : $configuration,
        );
    }

    public function findings(Paths $files, Withheld $withheld): Findings|CannotJudge
    {
        return $this->unlessLeaked($withheld, $this->originals);
    }

    public function readsDependents(): bool
    {
        return $this->dependents;
    }

    public function check(MutantCheck $check): Findings|OutOfScope|CannotJudge
    {
        $mutant = $check->mutant()->value();

        return $this->unlessLeaked($check->withheld(), array_key_exists($mutant, $this->mutants)
            ? $this->mutants[$mutant]
            : CannotJudge::because(sprintf('%s is no mutant the fake was told about.', $mutant)));
    }

    public function checks(MutantChecks $checks, ProcessCount $side): CheckAnswers
    {
        $answers = [];

        foreach ($checks as $check) {
            $answers[] = $this->check($check);
        }

        return CheckAnswers::of(...$answers);
    }

    /**
     * This answer, or why the process stopped, where it could see `LEAK`.
     *
     * @template T of object
     *
     * @param  T $answer
     * @return T|CannotJudge
     */
    private function unlessLeaked(Withheld $withheld, object $answer): object
    {
        return getenv(self::LEAK) !== false && preg_match($withheld->pattern(), self::LEAK) !== 1
            ? CannotJudge::because(sprintf('The analyser can see %s, which it runs without.', self::LEAK))
            : $answer;
    }
}
