<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_key_exists;
use function implode;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnit;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

use function sprintf;
use function str_replace;

/**
 * The verdict as one line per result, for an editor's problem matcher:
 * `<path>:<line>:<col>: <error|warning>: <message> [<rule>] <id>`. A result
 * is an error in a set that failed and a warning otherwise, under SARIF's
 * rules, and a proved or carried one names the run it came from. The format
 * is public API (ADR-0015, decisions 6 and 8, and ADR-0011).
 */
final readonly class Problems
{
    /** The line before each judgement, for a background matcher. */
    public const string JUDGING = 'mutation-gate: judging';

    /** The line after each judgement, for a background matcher. */
    public const string JUDGED = 'mutation-gate: judged';

    private const string LINE = '%s:%d:%d: %s: %s%s [%s] %s';

    /** How a result not run this time is marked: how it came, and the run its proof names, where it names one. */
    private const string MARK = ' (%s%s)';

    /**
     * Each result, one to a line, each ending in a line break.
     *
     * @param array<string, Contents> $sources each mutated file the gate could read, by its path
     */
    public static function text(Verdict $verdict, array $sources, ProblemsShown $shown): string
    {
        $overview = Overview::of($verdict);
        $marks = self::marks($verdict);
        $columns = self::columns($overview, $sources);
        $lines = [];

        foreach ($overview->survivors() as $judged) {
            $path = $judged->mutant()->location()->file()->value();

            if ($shown === ProblemsShown::All || $judged->isOnChangedLine()) {
                $mark = array_key_exists($path, $marks) ? $marks[$path] : '';
                $lines[] = self::line($judged, $columns[$path], $overview->isFailing($judged), $mark);
            }
        }

        return implode('', $lines);
    }

    /**
     * How each unit's results are marked, by its path.
     *
     * @return array<string, string>
     */
    private static function marks(Verdict $verdict): array
    {
        $marks = [];

        foreach ($verdict->units() as $unit) {
            $marks[$unit->unit()->path()->value()] = self::mark($unit);
        }

        return $marks;
    }

    /**
     * Where on its lines each mutant of each file with a result is, read from its tokens once per file.
     *
     * @param  array<string, Contents> $sources
     * @return array<string, Columns>
     */
    private static function columns(Overview $overview, array $sources): array
    {
        $columns = [];

        foreach ($overview->survivors() as $judged) {
            $path = $judged->mutant()->location()->file()->value();
            $columns[$path] = array_key_exists($path, $columns)
                ? $columns[$path]
                : Columns::in(array_key_exists($path, $sources) ? $sources[$path] : Contents::of(''));
        }

        return $columns;
    }

    private static function line(JudgedMutant $judged, Columns $columns, bool $failing, string $mark): string
    {
        $mutant = $judged->mutant();
        $start = $columns->of($mutant)['start'];

        return sprintf(
            "%s\n",
            sprintf(
                self::LINE,
                $mutant->location()->file()->value(),
                $start['line'],
                $start['column'],
                $failing ? 'error' : 'warning',
                str_replace(["\r\n", "\r", "\n"], ' ', MutantText::message($judged)),
                $mark,
                ResultRule::of($judged->judgement())->value,
                $mutant->id()->value(),
            ),
        );
    }

    /** Where a result not run this time came from: proved or carried, from the run whose proof it is. */
    private static function mark(JudgedUnit $unit): string
    {
        $run = $unit->run();
        $named = static fn(string $preposition): string => $run instanceof Run
            ? sprintf(' %s run %s', $preposition, $run->id())
            : '';

        return match ($unit->origin()) {
            Origin::Run => '',
            Origin::Proved => sprintf(self::MARK, 'proved', $named('in')),
            Origin::Carried => sprintf(self::MARK, 'carried', $named('from')),
        };
    }
}
