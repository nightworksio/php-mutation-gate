<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use function getcwd;
use function getenv;

use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\Sarif;
use NightWorksIO\MutationGate\Core\Report\SourceRoot;
use NightWorksIO\MutationGate\Core\Report\Unrooted;
use NightWorksIO\MutationGate\Core\Troubleshooting\Guide;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\Reporter;

/**
 * The reporter `sarif`: SARIF 2.1.0, for code scanning, written to the `path`
 * its entry names (ADR-0009). Outside CI it also names the
 * repository's root on this machine, for an editor's viewer (ADR-0015).
 *
 * @internal
 */
final readonly class SarifReportFile implements Reporter
{
    private function __construct(private ReportPath $path, private SourceRoot|Unrooted $root, private Guide $guide)
    {
    }

    /** The report as CI uploads it, naming no root, its rules linked into this guide. */
    public static function at(string $path, Guide $guide): self
    {
        return new self(ReportPath::at($path), Unrooted::report(), $guide);
    }

    /** The report for an editor, naming this absolute directory as the repository's root. */
    public static function rootedAt(string $path, string $root, Guide $guide): self
    {
        return new self(ReportPath::at($path), SourceRoot::at($root), $guide);
    }

    /** From its entry's `path`; in a run with `CI` unset, rooted at the directory the run started in. */
    public static function configured(Options $options, Guide $guide): self|Invalid
    {
        $path = ReportPath::ofFile($options, 'The SARIF report');
        $here = getenv('CI') === false || getenv('CI') === '' ? getcwd() : false;

        return match (true) {
            $path instanceof Invalid => $path,
            $here === false => new self($path, Unrooted::report(), $guide),
            default => new self($path, SourceRoot::at($here), $guide),
        };
    }

    public function report(Verdict $verdict): Written|NotWritten
    {
        return $this->path->write($this->root instanceof SourceRoot
            ? Sarif::rootedAt($verdict, $this->root, $this->guide)
            : Sarif::json($verdict, $this->guide));
    }
}
