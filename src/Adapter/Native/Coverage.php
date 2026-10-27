<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Native;

use function file_exists;
use function file_get_contents;
use function ini_get;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\HandoffLimits;
use NightWorksIO\MutationGate\Core\Coverage\PhpUnitXml;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Native\CoverageReport;
use NightWorksIO\MutationGate\Core\Native\Framework;
use NightWorksIO\MutationGate\Core\Native\Shell;
use NightWorksIO\MutationGate\Core\Native\TestTimes;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\CoverageFailure;
use NightWorksIO\MutationGate\Core\Runner\CoverageRan;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Leftover;

use function unlink;

/**
 * A per-test line map: this job's own run of the framework under coverage, the
 * report a run the project started in this job wrote, or the gate's own map
 * another job handed over, and never a runner's map another job wrote.
 * The framework runs its tests in one process, however many a run is given.
 */
final readonly class Coverage
{
    public function __construct(
        private Project $project,
        private Shell $shell,
        private Invocation $invocation,
        private Framework $framework,
    ) {
    }

    public function of(CoverageRun|CoverageRead|CoverageRan $request): CoverageMap|CannotJudge
    {
        return match (true) {
            $request instanceof CoverageRead => $this->handedOver($request),
            $request instanceof CoverageRan => $this->reportIn($request->directory()),
            default => $this->measured($request),
        };
    }

    /** The map the gate wrote into a directory of the project, or why there is none to read. */
    private function handedOver(CoverageRead $request): CoverageMap|CannotJudge
    {
        $file = $this->project->absolute(CoverageMapFile::in($request->directory()));
        $limits = HandoffLimits::under(ini_get('memory_limit'));
        $bytes = is_file($file) ? file_get_contents($file, length: $limits->readable()) : NotGiven::value();

        return is_string($bytes) ? CoverageMapFile::decode($bytes, $limits) : CoverageMapFile::missingAt($file);
    }

    /** The report a run the project started in this job left in a directory, or why there is none to read. */
    private function reportIn(Path $directory): CoverageMap|CannotJudge
    {
        return $this->read($this->mapIn($directory));
    }

    /** A run under coverage into the request's directory, with no earlier run's map left there. */
    private function measured(CoverageRun $request): CoverageMap|CannotJudge
    {
        $map = $this->mapIn($request->directory());
        $left = [$this->framework->report()->written($map), CoverageReport::timesBeside($map)];

        foreach ($left as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (file_exists($left[0])) {
            return Leftover::at($left[0]);
        }

        $ran = $this->shell->run($this->invocation->coverage($request, $map));

        return $ran->succeeded()
            ? $this->read($map)
            : CoverageFailure::said($this->framework->program(), $ran->output());
    }

    /** Where the framework's map is in a directory of the project, on disk. */
    private function mapIn(Path $directory): string
    {
        return $this->project->absolute($directory->child(Path::of($this->framework->report()->value)));
    }

    /**
     * The map the framework wrote: its PHP report, or its XML coverage timed
     * as the gate's recorder timed each test.
     */
    private function read(string $map): CoverageMap|CannotJudge
    {
        if ($this->framework->report() === CoverageReport::Php) {
            return CoverageFile::read($this->project, $map);
        }

        $xml = PhpUnitXml::read(
            Root::of($this->project->root()),
            DiskPath::of($map),
            static fn(DiskPath $file): string|false => is_file($file->value())
                ? file_get_contents($file->value())
                : false,
        );
        $times = CoverageReport::timesBeside($map);

        return $xml instanceof CannotJudge
            ? $xml
            : $xml->timedEach(...TestTimes::read(is_file($times) ? file_get_contents($times) : false));
    }
}
