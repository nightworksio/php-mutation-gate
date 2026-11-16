<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Outbreak;

use function array_keys;

use NightWorksIO\MutationGate\Core\Coverage\Admission;
use NightWorksIO\MutationGate\Core\Coverage\Conversion;
use NightWorksIO\MutationGate\Core\Coverage\EntryKeys;
use NightWorksIO\MutationGate\Core\Coverage\LoadedPackages;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Runner\RanReport;
use stdClass;

/**
 * What the gate asks `outbreak report outline` and `outbreak report convert`
 * of a run's report (ADR-0119), as compact JSON written as the gate writes
 * its map, so that the binary copies the seconds, packages, where the map was
 * measured and its entries' keys as they stand: the report and the format
 * the runner reads; the tests the run timed beside it and what they loaded;
 * and for a map, where it is written, each file's path in the project, the
 * tests leaving with the files each stays on, where it was measured and its
 * entries' keys.
 *
 * @internal
 */
final readonly class ReportAsked
{
    /** The request of the outline of a report's map. */
    public static function outline(RanReport $report): string
    {
        return JsonText::compact([
            'report' => $report->file(),
            'format' => $report->format(),
            'timed' => self::timed($report),
        ]);
    }

    /** The request of the map of a report, written into a file on disk. */
    public static function conversion(RanReport $report, Conversion $conversion, string $into): string
    {
        $admission = $conversion->admission();
        $at = $conversion->at();
        $keys = $conversion->keys();

        return JsonText::compact([
            'report' => $report->file(),
            'format' => $report->format(),
            'into' => $into,
            'paths' => self::object(self::paths($conversion)),
            'timed' => self::timed($report),
            'loads' => self::loads($report->recorded()->loads()),
            ...($admission instanceof Admission ? ['leaving' => self::object(self::leaving($admission))] : []),
            ...($at instanceof MeasuredAt ? ['measured' => $at->written()] : []),
            ...($keys instanceof EntryKeys ? ['keys' => self::object($keys->written())] : []),
        ]);
    }

    /** @return list<array{string, float}> each test the run timed, with its seconds */
    private static function timed(RanReport $report): array
    {
        $timed = [];

        foreach ($report->recorded()->timed() as $test) {
            $timed[] = [$test->test()->value(), $test->seconds()->seconds()];
        }

        return $timed;
    }

    /** @return array<string, string> each file's path in the project, by the name the report gives it */
    private static function paths(Conversion $conversion): array
    {
        $paths = [];

        foreach ($conversion->outline()->named() as $name => $path) {
            $paths[$name] = $path->value();
        }

        return $paths;
    }

    /** @return list<list<array{string, list<string>, bool}>> what each process's tests loaded */
    private static function loads(LoadedPackages $loads): array
    {
        $processes = [];

        foreach ($loads->processes() as $process) {
            $tests = [];

            foreach ($process->tests() as $test) {
                $tests[] = [$test->test()->value(), [...$test->packages()], $test->startsProcesses()];
            }

            $processes[] = $tests;
        }

        return $processes;
    }

    /** @return array<string, list<string>> the paths of the files each test leaving stays on, by its id */
    private static function leaving(Admission $admission): array
    {
        $leaving = [];

        foreach ($admission->staying() as $test => $files) {
            $leaving[$test] = array_keys($files);
        }

        return $leaving;
    }

    /**
     * An object as JSON writes it, `{}` where it has no member.
     *
     * @param  array<string, string|list<string>> $members
     * @return array<string, string|list<string>>|stdClass
     */
    private static function object(array $members): array|stdClass
    {
        return $members === [] ? new stdClass() : $members;
    }
}
