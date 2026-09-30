<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use function array_key_exists;
use function getenv;

use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Analysis\MutantCheck;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Port\StaticChecker;

use function preg_match;
use function sprintf;

/**
 * A static analyser that reports what it was told: about the originals, and
 * about each mutant it knows by its file. It cannot judge any other mutant.
 * Each answer stands for a process started in this process's environment
 * without what is withheld, which stops, as the contract fixture's bootstrap
 * does, where it can still see `LEAK`.
 */
final readonly class StaticCheckerFake implements StaticChecker
{
    /** The variable the contract suite sets and withholds, which the fixture's bootstrap refuses to see. */
    public const string LEAK = 'MUTATION_GATE_CONTRACT_TOKEN';

    /** @param array<string, Findings> $mutants what it reports of each mutant, by the mutant's file */
    public function __construct(
        private AnalyserIdentity|CannotJudge $identity,
        private Findings|CannotJudge $originals,
        private array $mutants,
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

    public function findings(Paths $files, Withheld $withheld): Findings|CannotJudge
    {
        return $this->unlessLeaked($withheld, $this->originals);
    }

    public function check(MutantCheck $check): Findings|CannotJudge
    {
        $mutant = $check->mutant()->value();

        return $this->unlessLeaked($check->withheld(), array_key_exists($mutant, $this->mutants)
            ? $this->mutants[$mutant]
            : CannotJudge::because(sprintf('%s is no mutant the fake was told about.', $mutant)));
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
