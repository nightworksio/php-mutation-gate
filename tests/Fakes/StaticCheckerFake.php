<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Port\StaticChecker;

use function sprintf;

/**
 * A static analyser that reports what it was told: about the originals, and
 * about each mutant it knows by its file. It cannot judge any other mutant.
 */
final readonly class StaticCheckerFake implements StaticChecker
{
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

    public function identity(): AnalyserIdentity|CannotJudge
    {
        return $this->identity;
    }

    public function findings(Paths $files, Withheld $withheld): Findings|CannotJudge
    {
        return $this->originals;
    }

    public function check(Path $original, Path $mutant, Withheld $withheld): Findings|CannotJudge
    {
        return array_key_exists($mutant->value(), $this->mutants)
            ? $this->mutants[$mutant->value()]
            : CannotJudge::because(sprintf('%s is no mutant the fake was told about.', $mutant->value()));
    }
}
