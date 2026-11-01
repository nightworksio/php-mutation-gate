<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Native;

use function dirname;

use NightWorksIO\MutationGate\Core\Coverage\PhpReport;
use NightWorksIO\MutationGate\Core\Coverage\PhpUnitXml;

use function sprintf;

/**
 * The coverage map a framework writes for the native runner (ADR-0023,
 * ADR-0027): php-code-coverage's PHP report, which times each test, or
 * PHPUnit's XML coverage in a directory, which times none, beside the times
 * the gate's own recorder wrote as each test ended.
 */
enum CoverageReport: string
{
    case Php = PhpReport::NAME;
    case Xml = 'coverage-xml';

    /** What the recorder names the file of each test's time, beside the XML coverage. */
    private const string TIMES = 'times.txt';

    /** The file a run that reads the map wrote whole once it ended: the PHP report, or the XML's index. */
    public function written(string $map): string
    {
        return $this === self::Php ? $map : sprintf('%s/%s', $map, PhpUnitXml::INDEX);
    }

    /** The file the recorder writes each test's time to, beside the XML coverage in this directory. */
    public static function timesBeside(string $map): string
    {
        return sprintf('%s/%s', dirname($map), self::TIMES);
    }
}
