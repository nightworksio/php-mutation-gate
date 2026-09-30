<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function file_exists;
use function file_get_contents;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\PhpReport;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\CoverageFailure;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Leftover;

use function unlink;

/**
 * A per-test line map: this job's own run of PHPUnit under coverage, or the
 * gate's own map another job handed over, and never a runner's map another
 * job wrote. PHPUnit runs its tests in one process, however many a run is
 * given.
 */
final readonly class Coverage
{
    public function __construct(private Project $project, private Shell $shell, private Invocation $invocation)
    {
    }

    public function of(CoverageRun|CoverageRead $request): CoverageMap|CannotJudge
    {
        return $request instanceof CoverageRead ? $this->handedOver($request) : $this->measured($request);
    }

    /** The map the gate wrote into a directory of the project, or why there is none to read. */
    private function handedOver(CoverageRead $request): CoverageMap|CannotJudge
    {
        $file = $this->project->absolute(CoverageMapFile::in($request->directory()));
        $bytes = is_file($file) ? file_get_contents($file) : false;

        return is_string($bytes) ? CoverageMapFile::decode($bytes) : CoverageMapFile::missingAt($file);
    }

    /** A run under coverage into the request's directory, with no earlier run's map left there. */
    private function measured(CoverageRun $request): CoverageMap|CannotJudge
    {
        $map = $this->project->absolute($request->directory()->child(Path::of(PhpReport::NAME)));

        if (is_file($map)) {
            unlink($map);
        }

        if (file_exists($map)) {
            return Leftover::at($map);
        }

        $ran = $this->shell->run($this->invocation->coverage($request, $map));

        return $ran->succeeded()
            ? CoverageFile::read($this->project, $map)
            : CoverageFailure::said('PHPUnit', $ran->output());
    }
}
