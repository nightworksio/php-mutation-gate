<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Runner\CoverageRequest;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
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
 * A runner that answers every coverage request so, keeping each request it
 * was asked, and answers the rest as another runner does.
 */
final class CoverageAsked implements Runner
{
    /** @var list<CoverageRequest> */
    private array $asked = [];

    public function __construct(private readonly Runner $runner, private readonly CoverageMap|CannotJudge $answer)
    {
    }

    /** @return list<CoverageRequest> each coverage request, in the order it was asked */
    public function asked(): array
    {
        return $this->asked;
    }

    public function identity(): Identity|CannotJudge
    {
        return $this->runner->identity();
    }

    public function groups(Withheld $withheld): Groups|CannotJudge
    {
        return $this->runner->groups($withheld);
    }

    public function coverage(CoverageRequest $request): CoverageMap|CannotJudge
    {
        $this->asked[] = $request;

        return $this->answer;
    }

    public function judges(Path $file, CoverageMap $map): Paths|CannotJudge
    {
        return $this->runner->judges($file, $map);
    }

    public function mutate(MutationRequest $request): MutationResult|CannotJudge
    {
        return $this->runner->mutate($request);
    }

    public function retry(
        Mutants $mutants,
        Seconds $limit,
        WholeSuite|Group|Filter $judgedBy,
        Withheld $withheld,
    ): Mutants|CannotJudge {
        return $this->runner->retry($mutants, $limit, $judgedBy, $withheld);
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
        return $this->runner->names($tests, $withheld);
    }

    public function rootedAt(Path $package): Runner|CannotJudge
    {
        return $this->runner->rootedAt($package);
    }
}
