<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\CoverageFailure;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Program;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

/**
 * The coverage directories Infection reads: the map another job handed on, in
 * Infection's layout, or PHPUnit's run of the tests that judge a run, under
 * coverage, each held so a run again reads what the run it follows left.
 */
final readonly class Covering
{
    public function __construct(private Project $project, private Shell $shell, private HeldCoverage $held)
    {
    }

    /**
     * The coverage directory a run reads, which the adapter writes unless the
     * mutation run a run again follows left the same there: for a run judged by
     * the whole suite that reuses the maps a plan handed on, the shard's own
     * map in Infection's layout; otherwise PHPUnit's run of the tests that
     * judge it.
     * A held path never reads a map of the whole suite.
     */
    public function of(OwnConfig $config, MutationRequest $request): DiskPath|CannotJudge
    {
        $handed = $request->coverage();

        if ($handed instanceof Handed && $request->judgedBy() instanceof WholeSuite) {
            $shard = $handed->own();

            return $this->held->handedOn($shard, fn(): DiskPath|CannotJudge => $this->handedOn($shard));
        }

        $own = $this->ownCoverage();
        $run = Invocation::coverage($this->project, $config, $request->judgedBy(), $own, $request->narrowing()->suite())
            ->withholding($request->withheld());

        return $this->held->ranBy($run, fn(): DiskPath|CannotJudge => $this->covered($run, $own));
    }

    /**
     * The directory, once PHPUnit has run the tests a coverage run asks for
     * under coverage into it, of its suite alone where it names one, never
     * seeing a variable withheld, with no earlier run's reports left.
     */
    public function run(OwnConfig $config, CoverageRun $request, DiskPath $directory): DiskPath|CannotJudge
    {
        return $this->covered(
            Invocation::coverage($this->project, $config, $request->tests(), $directory, $request->suite())
                ->withholding($request->withheld()),
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

        return $ran->succeeded() ? $directory : CoverageFailure::said(Program::PhpUnit, $ran->output());
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
        return $this->project->directory(Path::of($this->project->own(CoverageRun::OWN_DIRECTORY)));
    }
}
