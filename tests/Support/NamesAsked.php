<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Reproducible;
use NightWorksIO\MutationGate\Core\Runner\Reproduction;
use NightWorksIO\MutationGate\Core\Runner\RunnerBehaviour;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Port\Runner;

/**
 * A runner that names tests as another runner does, or cannot name them at
 * all, keeping each time it was asked, and answers the rest as that runner does.
 */
final class NamesAsked implements Runner
{
    /** @var list<array{TestIds, Withheld}> */
    private array $asked = [];

    private function __construct(private readonly Runner $runner, private readonly CannotJudge|Runner $naming)
    {
    }

    /** This runner, keeping each time it was asked to name tests. */
    public static function of(Runner $runner): self
    {
        return new self($runner, $runner);
    }

    /** This runner, which cannot name its tests. */
    public static function refusing(Runner $runner, string $why): self
    {
        return new self($runner, CannotJudge::because($why));
    }

    /** @return list<array{TestIds, Withheld}> the tests and the withheld variables of each time it was asked, in order */
    public function asked(): array
    {
        return $this->asked;
    }

    public function behaviour(): RunnerBehaviour
    {
        return $this->runner->behaviour();
    }

    public function identity(Withheld $withheld): Identity|CannotJudge
    {
        return $this->runner->identity($withheld);
    }

    public function groups(Withheld $withheld): Groups|CannotJudge
    {
        return $this->runner->groups($withheld);
    }

    public function coverage(CoverageRun|CoverageRead $request): CoverageMap|CannotJudge
    {
        return $this->runner->coverage($request);
    }

    public function judges(Path $file, CoverageMap $map): Paths|CannotJudge
    {
        return $this->runner->judges($file, $map);
    }

    public function mutate(MutationRequest $request): MutationResult|CannotJudge
    {
        return $this->runner->mutate($request);
    }

    public function retry(MutationRequest $request, Mutants $mutants, Seconds $limit): Mutants|CannotJudge
    {
        return $this->runner->retry($request, $mutants, $limit);
    }

    public function reproduce(
        Reproducible $mutant,
        WholeSuite|Group|Filter $judgedBy,
        Seconds $limit,
        Withheld $withheld,
    ): Reproduction|CannotJudge {
        return $this->runner->reproduce($mutant, $judgedBy, $limit, $withheld);
    }

    public function markers(Paths $files): Markers|CannotJudge
    {
        return $this->runner->markers($files);
    }

    public function definitions(): Paths
    {
        return $this->runner->definitions();
    }

    public function names(TestIds $tests, Withheld $withheld): TestNames|CannotJudge
    {
        $this->asked[] = [$tests, $withheld];

        return $this->naming instanceof Runner ? $this->naming->names($tests, $withheld) : $this->naming;
    }

    public function rootedAt(Path $package): Runner|CannotJudge
    {
        return $this->runner->rootedAt($package);
    }
}
