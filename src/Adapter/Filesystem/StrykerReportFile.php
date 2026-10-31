<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\Stryker;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Port\Reporter;

/**
 * The reporter `stryker`: the verdict in the `mutation-testing-report-schema`
 * format, with the source of every mutated file, written to the `path` its
 * entry names, for Stryker's dashboard and the viewers that read the format
 * (ADR-0046).
 */
final readonly class StrykerReportFile implements Configurable, Reporter
{
    private function __construct(private ReportPath $path, private Directory $project)
    {
    }

    /** A report at this path, with the project's files read from this directory. */
    public static function at(string $path, string $project): self
    {
        return new self(ReportPath::at($path), Directory::at($project));
    }

    public static function fromOptions(Options $options): self|Invalid
    {
        $path = ReportPath::ofFile($options, 'The Stryker report');

        return $path instanceof Invalid ? $path : new self($path, Directory::at('.'));
    }

    public function report(Verdict $verdict): Written|NotWritten
    {
        return $this->path->write(
            Stryker::json($verdict, MutatedFiles::in($this->project)->of($verdict->trees()->mutants())),
        );
    }
}
