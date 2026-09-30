<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function count;
use function implode;

use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Matrix\Redundancy;
use NightWorksIO\MutationGate\Core\Matrix\Standing;
use NightWorksIO\MutationGate\Core\Matrix\TestStandings;
use NightWorksIO\MutationGate\Core\Matrix\WholeTest;
use NightWorksIO\MutationGate\Core\Test\TestRow;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

use function rtrim;
use function sprintf;

/**
 * The `tests` report as the console prints it: the same lists as its
 * Markdown, each test on one plain line under its section, indented, with
 * each test's name as its runner gave it (ADR-0014, decision 5).
 */
final readonly class TestsText
{
    /** How far the console indents a line under its heading. */
    public const string INDENT = '  ';

    private const string NONE = 'None.';

    private const string UNTIMED = 'untimed';

    /** What a removable test that makes no kill kills. */
    private const string NOTHING = 'nothing';

    public static function of(Verdict $verdict): string
    {
        $standings = TestStandings::of($verdict);
        $useless = TestsReport::hasCoverage($verdict) ? [
            ...self::section(TestsReport::KILLS_NOTHING, $standings->thatStand(Standing::KillsNothing), []),
            ...self::section(
                TestsReport::NEVER_FIRST,
                $standings->thatStand(Standing::NeverFirst),
                [TestsReport::SUSPICION],
            ),
        ] : ['', TestsReport::NO_COVERAGE];

        $lines = [
            TestsReport::TITLE,
            rtrim(sprintf('%s %s', TestsReport::SCOPE, TestsReport::unassessedOf($standings))),
            ...$useless,
            ...self::removable($verdict),
            ...WeakAssertions::text($verdict),
        ];

        return sprintf("%s\n", implode("\n", $lines));
    }

    /**
     * A section of useless tests: a blank line, its heading and count, what it means, and a line for each test.
     *
     * @param  list<string> $note
     * @return list<string>
     */
    private static function section(string $heading, TestStandings $tests, array $note): array
    {
        $lines = [];

        foreach ($tests as $test) {
            $lines[] = sprintf(
                '%s%s  judged %d%s',
                self::INDENT,
                Fit::plain($test->test()->value()),
                $test->judged(),
                self::rowsOf($test),
            );
        }

        return [
            '',
            sprintf('%s (%d)', $heading, count($tests)),
            ...count($tests) === 0 ? [sprintf('%s%s', self::INDENT, self::NONE)] : [],
            ...count($tests) === 0 ? [] : self::indented($note),
            ...$lines,
        ];
    }

    /** Each data set row of a test with how it stands, after the test; nothing for a test with no rows. */
    private static function rowsOf(WholeTest $test): string
    {
        $rows = [];

        foreach ($test as $row) {
            $name = $row->name();

            if ($name instanceof TestRow) {
                $rows[] = sprintf('%s %s', Fit::plain($name->row()), $row->standing()->value);
            }
        }

        return $rows === [] ? '' : sprintf('  rows: %s', implode(', ', $rows));
    }

    /**
     * The removable tests, each with its time and the kept test that makes each of its kills; or why there are none.
     *
     * @return list<string>
     */
    private static function removable(Verdict $verdict): array
    {
        if ($verdict->matrix()->kind() !== MatrixKind::Full) {
            return ['', TestsReport::REMOVABLE, ...self::indented([$verdict->matrix()->whyNotFull()->sentence()])];
        }

        $lines = [];

        foreach (Redundancy::of($verdict)->removable() as $test) {
            $kills = [];

            foreach ($test as $kill) {
                $kills[] = sprintf('%s by %s', $kill->mutant()->value(), Fit::plain($kill->keptBy()->value()));
            }

            $seconds = $test->seconds();
            $lines[] = sprintf(
                '%s%s  %s  kills %s',
                self::INDENT,
                Fit::plain($test->test()->value()),
                $seconds instanceof Seconds ? $seconds->preciseText() : self::UNTIMED,
                $kills === [] ? self::NOTHING : implode(', ', $kills),
            );
        }

        return [
            '',
            sprintf('%s (%d)', TestsReport::REMOVABLE, count($lines)),
            ...$lines === [] ? [sprintf('%s%s', self::INDENT, self::NONE)] : $lines,
            ...self::indented([TestsReport::SMALL]),
        ];
    }

    /**
     * @param  list<string> $lines
     * @return list<string>
     */
    private static function indented(array $lines): array
    {
        $indented = [];

        foreach ($lines as $line) {
            $indented[] = sprintf('%s%s', self::INDENT, $line);
        }

        return $indented;
    }
}
