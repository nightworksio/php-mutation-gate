<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_key_exists;
use function count;
use function explode;
use function implode;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Hint\Change;
use NightWorksIO\MutationGate\Core\Matrix\KillMatrix;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Verdict\JudgedKill;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

use function sprintf;

use stdClass;

/**
 * The verdict in the open `mutation-testing-report-schema` format that
 * Stryker's viewer reads. The viewer computes its own score, so each mutant is
 * given the viewer status the gate's score treats the same way, and the page
 * shows the gate's score; the gate's own judgement goes in `statusReason`
 * (ADR-0009, decision 4).
 *
 * @phpstan-type Place array{line: int, column: int}
 * @phpstan-type MutantEntry array{
 *     id: string,
 *     mutatorName: string,
 *     replacement?: string,
 *     location: array{start: Place, end: Place},
 *     status: string,
 *     statusReason: string,
 *     description: string,
 *     coveredBy: list<string>,
 *     killedBy: list<string>,
 *     testsCompleted: int,
 * }
 * @phpstan-type TestFile array{tests: list<array{id: string, name: string}>}
 */
final readonly class Stryker
{
    private const string SCHEMA_VERSION = '2';

    /** Stryker's own defaults for the colours of its scores. */
    private const array THRESHOLDS = ['high' => 80, 'low' => 60];

    private const string LANGUAGE = 'php';

    /**
     * @param array<string, Contents> $sources each mutated file the gate could read, by its path
     */
    public static function json(Verdict $verdict, array $sources): string
    {
        $uncovered = Overview::of($verdict)->uncovered();
        $files = [];
        $columns = [];

        foreach ($verdict->trees()->mutants() as $judged) {
            $path = $judged->mutant()->location()->file()->value();
            $source = array_key_exists($path, $sources) ? $sources[$path] : Contents::of('');
            $columns[$path] = array_key_exists($path, $columns) ? $columns[$path] : Columns::in($source);
            $file = array_key_exists($path, $files)
                ? $files[$path]
                : ['language' => self::LANGUAGE, 'source' => $source->text(), 'mutants' => []];
            $file['mutants'][] = self::mutant($judged, $columns[$path], $uncovered, $verdict->matrix());
            $files[$path] = $file;
        }

        return JsonText::encode([
            'schemaVersion' => self::SCHEMA_VERSION,
            'thresholds' => self::THRESHOLDS,
            'projectRoot' => '.',
            'framework' => ['name' => 'mutation-gate'],
            'files' => $files === [] ? new stdClass() : $files,
            'testFiles' => self::testFiles($verdict),
        ]);
    }

    /**
     * Every test a mutant names, under the file it is in, or the class its id names where the runner named it nothing.
     *
     * @return array<string, TestFile>|stdClass
     */
    private static function testFiles(Verdict $verdict): array|stdClass
    {
        $files = [];

        foreach (TestTable::of($verdict)->tests() as $test) {
            $name = $verdict->matrix()->names()->nameOf($test);
            $whole = $verdict->matrix()->names()->testOf($test);
            $file = $whole instanceof TestName ? $whole->file()->value() : explode('::', $test->value())[0];
            $files[$file]['tests'][] = [
                'id' => $test->value(),
                'name' => $name instanceof TestId ? $test->value() : $name->description(),
            ];
        }

        return $files === [] ? new stdClass() : $files;
    }

    /** @return MutantEntry */
    private static function mutant(
        JudgedMutant|JudgedKill $judged,
        Columns $columns,
        Uncovered $uncovered,
        KillMatrix $matrix,
    ): array {
        $covering = $matrix->coveredBy($judged);
        $completed = 0;

        foreach ($covering as $test) {
            $completed += $matrix->outcome($judged, $test)->ran() ? 1 : 0;
        }

        $mutant = $judged->mutant();
        $reason = $mutant->reason();

        return [
            'id' => $mutant->id()->value(),
            'mutatorName' => Mutator::short($mutant->mutator()),
            ...$mutant instanceof Mutant ? ['replacement' => Change::of($mutant->mutation()->diff())->added()] : [],
            'location' => $columns->of($mutant),
            'status' => self::statusOf($judged->judgement(), $uncovered),
            'statusReason' => $reason instanceof Reason
                ? sprintf('%s: %s', Label::of($judged->judgement()), $reason->text())
                : Label::of($judged->judgement()),
            'description' => self::description($judged, $matrix->names()),
            'coveredBy' => self::ids($covering),
            'killedBy' => self::ids($judged->mutant()->killers()),
            'testsCompleted' => $completed,
        ];
    }

    /** Judging tests by name, the hint, and the reproduce and explain commands, one to a line. */
    private static function description(JudgedMutant|JudgedKill $judged, TestNames $names): string
    {
        return implode("\n", [
            ...count($judged->tests()) > 0 ? [sprintf(MutantText::JUDGED_BY, $names->listed($judged->tests()))] : [],
            $judged->hint()->text(),
            sprintf('Reproduce: %s', $judged->reproduce()),
            sprintf('Explain: %s', $judged->explain()),
        ]);
    }

    /** @return list<string> */
    private static function ids(TestIds $tests): array
    {
        $ids = [];

        foreach ($tests as $test) {
            $ids[] = $test->value();
        }

        return $ids;
    }

    /** The viewer status the gate's score treats the same way. */
    private static function statusOf(MutantJudgement $judgement, Uncovered $uncovered): string
    {
        return match ($judgement) {
            MutantJudgement::Killed, MutantJudgement::KilledByStaticAnalysis, MutantJudgement::Errored => 'Killed',
            MutantJudgement::KilledByTimeout => 'Timeout',
            MutantJudgement::Survived,
            MutantJudgement::Unjudged,
            MutantJudgement::Flaky,
            MutantJudgement::TooSlowToJudge => 'Survived',
            MutantJudgement::Uncovered => $uncovered === Uncovered::Exclude ? 'Ignored' : 'NoCoverage',
            MutantJudgement::Ignored, MutantJudgement::IgnoredByMarker, MutantJudgement::Equivalent => 'Ignored',
        };
    }
}
