<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\Overview;
use NightWorksIO\MutationGate\Core\Report\Sonar;
use NightWorksIO\MutationGate\Core\Troubleshooting\Guide;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\Reporter;

/**
 * The reporter `sonar`: SonarQube's generic external-issues format, an issue
 * per mutant counted as not killed, written to the `path` its entry names,
 * with how many issues it wrote under each directory at the root beside the
 * line that says where (ADR-0028, decisions 7 and 10).
 */
final readonly class SonarReportFile implements Reporter
{
    private function __construct(private ReportPath $path, private Directory $project, private Guide $guide)
    {
    }

    /** A report at this path, with the mutated files read under this directory and the rules linking this guide. */
    public static function at(string $path, string $project, Guide $guide): self
    {
        return new self(ReportPath::at($path), Directory::at($project), $guide);
    }

    /** From its entry's `path`, with the mutated files read from where the gate runs. */
    public static function configured(Options $options, Guide $guide): self|Invalid
    {
        $path = ReportPath::ofFile($options, 'The SonarQube report');

        return $path instanceof Invalid ? $path : new self($path, Directory::at('.'), $guide);
    }

    public function report(Verdict $verdict): Written|NotWritten
    {
        $sources = MutatedFiles::in($this->project)->of(Overview::of($verdict)->survivors());
        $written = $this->path->write(Sonar::json($verdict, $sources, $this->guide));

        return $written instanceof Written ? Written::noting($written->where(), Sonar::tally($verdict)) : $written;
    }
}
