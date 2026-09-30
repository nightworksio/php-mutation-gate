<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function count;
use function implode;

use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Matrix\Redundancy;
use NightWorksIO\MutationGate\Core\Matrix\RemovableTest;
use NightWorksIO\MutationGate\Core\Matrix\Standing;
use NightWorksIO\MutationGate\Core\Matrix\TestStandings;
use NightWorksIO\MutationGate\Core\Matrix\WholeTest;
use NightWorksIO\MutationGate\Core\Test\TestRow;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

use function sprintf;

/**
 * The `tests` report, as JSON and as its Markdown twin: the tests that kill
 * nothing they judged, those never the first to kill, from a full kill
 * matrix those that can go together without losing a kill, and those whose
 * weak assertions let survivors through. It judges mutation kills only, and
 * never fails anything (ADR-0014, decisions 2 to 8; ADR-0025, decision 7).
 *
 * @phpstan-type Row array{name: string, standing: value-of<Standing>}
 * @phpstan-type Useless array{test: string, standing: value-of<Standing>, covers: int, rows?: list<Row>}
 * @phpstan-type Kill array{mutant: string, keptBy: string}
 * @phpstan-type Removable array{test: string, seconds?: float, kills: list<Kill>}
 * @phpstan-type Redundant array{needs: string}|array{kept: list<string>, removable: list<Removable>}
 */
final readonly class TestsReport
{
    /** The version of this format, which changes only when a reader would misread the new one. */
    public const int FORMAT = 1;

    /** What the report is about. */
    public const string TITLE = 'Tests mutation cannot see';

    public const string SCOPE = 'This judges mutation kills only, and never fails anything.';

    /** The first tier of useless tests. */
    public const string KILLS_NOTHING = 'Kills nothing it covers';

    /** The second tier, a suspicion under first killers. */
    public const string NEVER_FIRST = 'Never the first to kill';

    public const string REMOVABLE = 'Removable';

    public const string SUSPICION
        = 'A suspicion, not a finding: run `mutation-gate run --kill-matrix=full` to settle it.';

    public const string SMALL = 'The kept set is a small one, not the smallest.';

    /** What the report says in place of the useless tests where the verdict holds no coverage. */
    public const string NO_COVERAGE
        = 'The verdict holds no coverage map, so no test can be judged by what it kills.';

    private const string NOT_ASSESSED = '%d tests judged no mutant with a known result, so they are not assessed.';

    private const string ONE_NOT_ASSESSED = 'One test judged no mutant with a known result, so it is not assessed.';

    public static function json(Verdict $verdict): string
    {
        $standings = TestStandings::of($verdict);
        $useless = [];

        foreach ([Standing::KillsNothing, Standing::NeverFirst] as $standing) {
            foreach ($standings->thatStand($standing) as $test) {
                $useless[] = self::useless($test);
            }
        }

        return JsonText::encode([
            'format' => self::FORMAT,
            'matrix' => $verdict->matrix()->kind()->value,
            ...self::hasCoverage($verdict) ? [] : ['noCoverage' => self::NO_COVERAGE],
            'useless' => $useless,
            'notAssessed' => count($standings->thatStand(Standing::NotAssessed)),
            'redundant' => self::redundant($verdict),
            'weak' => WeakAssertions::of($verdict),
        ]);
    }

    public static function markdown(Verdict $verdict): string
    {
        $standings = TestStandings::of($verdict);
        $unassessed = count($standings->thatStand(Standing::NotAssessed));

        $useless = self::hasCoverage($verdict) ? [
            ...self::table(self::KILLS_NOTHING, $standings->thatStand(Standing::KillsNothing), []),
            ...self::table(self::NEVER_FIRST, $standings->thatStand(Standing::NeverFirst), [self::SUSPICION]),
        ] : [self::NO_COVERAGE];
        $blocks = [
            sprintf('# %s', self::TITLE),
            implode(' ', [self::SCOPE, ...self::unassessed($unassessed)]),
            ...$useless,
            ...self::removable($verdict),
            ...WeakAssertions::markdown($verdict),
        ];

        return sprintf("%s\n", implode("\n\n", $blocks));
    }

    /** The console's form of the report: the same lists as the Markdown, as plain lines. */
    public static function text(Verdict $verdict): string
    {
        return TestsText::of($verdict);
    }

    /** Whether any test of the verdict's coverage map ran, so a test can be judged by what it kills. */
    public static function hasCoverage(Verdict $verdict): bool
    {
        return count($verdict->matrix()->coverage()->tests()) > 0;
    }

    /** How many tests could not be assessed, as a sentence; nothing where every test was. */
    public static function unassessedOf(TestStandings $standings): string
    {
        return implode(' ', self::unassessed(count($standings->thatStand(Standing::NotAssessed))));
    }

    /** @return list<string> */
    private static function unassessed(int $tests): array
    {
        return match ($tests) {
            0 => [],
            1 => [self::ONE_NOT_ASSESSED],
            default => [sprintf(self::NOT_ASSESSED, $tests)],
        };
    }

    /** @return Useless */
    private static function useless(WholeTest $test): array
    {
        $rows = [];

        foreach ($test as $row) {
            $name = $row->name();
            $rows = $name instanceof TestRow
                ? [...$rows, ['name' => $name->value(), 'standing' => $row->standing()->value]]
                : $rows;
        }

        return [
            'test' => $test->test()->value(),
            'standing' => $test->standing()->value,
            'covers' => $test->judged(),
            ...$rows === [] ? [] : ['rows' => $rows],
        ];
    }

    /** @return Redundant */
    private static function redundant(Verdict $verdict): array
    {
        if ($verdict->matrix()->kind() !== MatrixKind::Full) {
            return ['needs' => $verdict->matrix()->whyNotFull()->sentence()];
        }

        $redundancy = Redundancy::of($verdict);
        $kept = [];
        $removable = [];

        foreach ($redundancy->kept() as $test) {
            $kept[] = $test->value();
        }

        foreach ($redundancy->removable() as $test) {
            $removable[] = self::removableEntry($test);
        }

        return ['kept' => $kept, 'removable' => $removable];
    }

    /** @return Removable */
    private static function removableEntry(RemovableTest $test): array
    {
        $kills = [];

        foreach ($test as $kill) {
            $kills[] = ['mutant' => $kill->mutant()->value(), 'keptBy' => $kill->keptBy()->value()];
        }

        $seconds = $test->seconds();

        return [
            'test' => $test->test()->value(),
            ...$seconds instanceof Seconds ? ['seconds' => $seconds->seconds()] : [],
            'kills' => $kills,
        ];
    }

    /**
     * A section of useless tests: its heading and count, what it means, and a table of them.
     *
     * @param  list<string> $note
     * @return list<string>
     */
    private static function table(string $heading, TestStandings $tests, array $note): array
    {
        $rows = ['| Test | Mutants judged | Rows |', '|---|---|---|'];

        foreach ($tests as $test) {
            $rows[] = sprintf(
                '| %s | %d | %s |',
                Escape::code($test->test()->value()),
                $test->judged(),
                self::rowsOf($test),
            );
        }

        return count($tests) === 0
            ? [sprintf('## %s (0)', $heading), 'None.']
            : [sprintf('## %s (%d)', $heading, count($tests)), ...$note, implode("\n", $rows)];
    }

    /** Each data set row of a test, with how it stands, for a cell of the table. */
    private static function rowsOf(WholeTest $test): string
    {
        $rows = [];

        foreach ($test as $row) {
            $name = $row->name();
            $rows = $name instanceof TestRow
                ? [...$rows, sprintf('%s: %s', Escape::code($name->row()), $row->standing()->value)]
                : $rows;
        }

        return implode('<br>', $rows);
    }

    /**
     * The removable tests, each with its time and the kept test that makes each of its kills; or why there are none.
     *
     * @return list<string>
     */
    private static function removable(Verdict $verdict): array
    {
        if ($verdict->matrix()->kind() !== MatrixKind::Full) {
            return [sprintf('## %s', self::REMOVABLE), $verdict->matrix()->whyNotFull()->sentence()];
        }

        $rows = ['| Test | Time | Its kills, each with the kept test that makes it too |', '|---|---|---|'];
        $count = 0;

        foreach (Redundancy::of($verdict)->removable() as $test) {
            $kills = [];

            foreach ($test as $kill) {
                $kills[] = sprintf(
                    '%s by %s',
                    Escape::code($kill->mutant()->value()),
                    Escape::code($kill->keptBy()->value()),
                );
            }

            $rows[] = sprintf(
                '| %s | %s | %s |',
                Escape::code($test->test()->value()),
                $test->seconds() instanceof Seconds ? $test->seconds()->preciseText() : 'untimed',
                implode('<br>', $kills),
            );
            ++$count;
        }

        return [
            sprintf('## %s (%d)', self::REMOVABLE, $count),
            $count === 0 ? 'None.' : implode("\n", $rows),
            self::SMALL,
        ];
    }
}
