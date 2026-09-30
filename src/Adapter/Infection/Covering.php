<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

use function sprintf;

/**
 * The coverage directories Infection reads: the map another job handed on, in
 * Infection's layout, or PHPUnit's run of the tests that judge a run, under
 * coverage, each held so a run again reads what the run it follows left.
 */
final readonly class Covering
{
    private const string COVERAGE_FAILED = "PHPUnit's coverage run failed. PHPUnit said:\n%s";

    public function __construct(private Project $project, private Shell $shell, private HeldCoverage $held)
    {
    }

    /**
     * The coverage directory a run reads, which the adapter writes unless the
     * mutation run a run again follows left the same there: for a run judged by
     * the whole suite that reuses the map another job handed on, that map in
     * Infection's layout; otherwise PHPUnit's run of the tests that judge it.
     * A held path never reads a map of the whole suite.
     */
    public function of(OwnConfig $config, MutationRequest $request): DiskPath|CannotJudge
    {
        $reused = $request->coverage();

        if ($reused instanceof Path && $request->judgedBy() instanceof WholeSuite) {
            return $this->held->handedOn($reused, fn(): DiskPath|CannotJudge => $this->handedOn($reused));
        }

        $own = $this->ownCoverage();
        $run = Invocation::coverage($this->project, $config, $request->judgedBy(), $own)
            ->withholding($request->withheld());

        return $this->held->ranBy($run, fn(): DiskPath|CannotJudge => $this->covered($run, $own));
    }

    /**
     * The directory, once PHPUnit has run these tests under coverage into it,
     * never seeing a variable withheld, with no earlier run's reports left.
     */
    public function run(
        OwnConfig $config,
        WholeSuite|Group|Filter $tests,
        Withheld $withheld,
        DiskPath $directory,
    ): DiskPath|CannotJudge {
        return $this->covered(
            Invocation::coverage($this->project, $config, $tests, $directory)->withholding($withheld),
            $directory,
        );
    }

    /**
     * The directory, once the coverage run has run into it, with no earlier
     * run's reports left.
     */
    private function covered(Command $run, DiskPath $directory): DiskPath|CannotJudge
    {
        foreach ([CoverageXml::indexIn($directory), $directory->child(Invocation::JUNIT)] as $report) {
            $fresh = $this->project->fresh($report->value());

            if ($fresh instanceof CannotJudge) {
                return $fresh;
            }
        }

        $ran = $this->shell->run($run);

        return $ran->succeeded() ? $directory : CannotJudge::because(sprintf(self::COVERAGE_FAILED, $ran->output()));
    }

    /** The map another job handed on in a directory, written into Infection's layout. */
    private function handedOn(Path $directory): DiskPath|CannotJudge
    {
        $map = HandedMap::in($this->project, $directory);

        return $map instanceof CannotJudge ? $map : CoverageLayout::write($this->project, $map, $this->ownCoverage());
    }

    /** The directory the adapter runs PHPUnit under coverage into, for a run of its own. */
    private function ownCoverage(): DiskPath
    {
        return $this->project->directory(Path::of($this->project->own(Invocation::COVERAGE)));
    }
}
