<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Outbreak;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\Coverage\MapOutline;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Runner\RanReport;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

/**
 * What `outbreak report outline` and `outbreak report convert` answered of a
 * run's report (ADR-0119): the outline, `{"outline": {"files": [<file as the
 * report names it>, …], "tests": [[<test>, [<place of a file it ran a line
 * of>, …]], …]}}`, each file at its path in the project as the runner names
 * it; that the map is written, `{"written": 1}`; or `{"declined": <why>}`.
 *
 * @internal
 */
final readonly class ReportAnswer
{
    /** Why the binary read no report, where it said so. */
    public const string DECLINED = 'Outbreak declined to read the report: %s';

    /** Why the binary read no report, where its answer said otherwise. */
    public const string UNREAD = 'Outbreak did not read the report: it answered %s';

    /** The outline an answer holds, or why it holds none. */
    public static function outline(Node $said, RanReport $report): MapOutline|NotWritten
    {
        $declined = $said->field('declined');

        try {
            return $declined->isPresent()
                ? NotWritten::because(sprintf(self::DECLINED, $declined->text()))
                : self::outlined($said->field('outline'), $report);
        } catch (NotInShape) {
            return NotWritten::because(sprintf(self::UNREAD, $said->json()));
        }
    }

    /** That the map an answer speaks of was written where it was asked, or why it was not. */
    public static function written(Node $said, string $map): Written|NotWritten
    {
        $declined = $said->field('declined');
        $unread = NotWritten::because(sprintf(self::UNREAD, $said->json()));

        try {
            return match (true) {
                $declined->isPresent() => NotWritten::because(sprintf(self::DECLINED, $declined->text())),
                $said->field('written')->integer() === 1 => Written::to($map),
                default => $unread,
            };
        } catch (NotInShape) {
            return $unread;
        }
    }

    /** @throws NotInShape */
    private static function outlined(Node $outline, RanReport $report): MapOutline
    {
        $files = [];
        $named = [];

        foreach ($outline->field('files')->items() as $file) {
            $path = $report->relative($file->text());
            $files[] = $path;
            $named[$file->text()] = $path;
        }

        $tests = [];
        $ran = [];

        foreach ($outline->field('tests')->items() as $test) {
            $id = $test->entry('0')->text();
            $tests[] = TestId::of($id);
            $ran[$id] = self::ran($test->entry('1')->integers(), $files, $test);
        }

        return MapOutline::listed(TestIds::of(...$tests), $ran, $named);
    }

    /**
     * @param  list<int>           $places
     * @param  list<Path>          $files
     * @return array<string, Path> the files at these places, by their paths
     *
     * @throws NotInShape
     */
    private static function ran(array $places, array $files, Node $test): array
    {
        $ran = [];

        foreach ($places as $place) {
            $file = array_key_exists($place, $files)
                ? $files[$place]
                : throw NotInShape::at($test->at(), 'the place of a file');
            $ran[$file->value()] = $file;
        }

        return $ran;
    }
}
