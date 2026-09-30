<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Filesystem;

use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\TestsReport;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Port\Reporter;

use function sprintf;
use function str_ends_with;

/**
 * The reporter `tests`: the tests mutation says catch nothing, and those
 * that can go, as JSON at the `path` its entry names and as Markdown beside
 * it, the same path with `.md` in place of `.json` (ADR-0014, decision 5).
 */
final readonly class TestsReportFile implements Configurable, Reporter
{
    private const string JSON = '.json';

    private function __construct(private ReportPath $json, private ReportPath $markdown)
    {
    }

    public static function at(string $path): self
    {
        $stem = str_ends_with($path, self::JSON) ? mb_substr($path, 0, -mb_strlen(self::JSON)) : $path;

        return new self(ReportPath::at($path), ReportPath::at(sprintf('%s.md', $stem)));
    }

    public static function fromOptions(Options $options): self|Invalid
    {
        $path = ReportPath::ofFile($options, 'The tests report');

        return $path instanceof Invalid ? $path : self::at($path->value());
    }

    public function report(Verdict $verdict): Written|NotWritten
    {
        $written = $this->json->write(TestsReport::json($verdict));

        return $written instanceof NotWritten ? $written : $this->markdown->write(TestsReport::markdown($verdict));
    }
}
