<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_key_exists;
use function count;
use function implode;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Hint\Change;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
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

        foreach ($verdict->mutants() as $judged) {
            $path = $judged->mutant()->location()->file()->value();
            $source = array_key_exists($path, $sources) ? $sources[$path] : Contents::of('');
            $file = array_key_exists($path, $files)
                ? $files[$path]
                : ['language' => self::LANGUAGE, 'source' => $source->text(), 'mutants' => []];
            $file['mutants'][] = self::mutant($judged, $source, $uncovered);
            $files[$path] = $file;
        }

        return Json::encode([
            'schemaVersion' => self::SCHEMA_VERSION,
            'thresholds' => self::THRESHOLDS,
            'projectRoot' => '.',
            'framework' => ['name' => 'mutation-gate'],
            'files' => $files === [] ? new stdClass() : $files,
        ]);
    }

    /** @return array<string, mixed> */
    private static function mutant(JudgedMutant $judged, Contents $source, Uncovered $uncovered): array
    {
        $mutant = $judged->mutant();
        $columns = Columns::of($mutant, $source);
        $reason = $mutant->reason();
        $change = Change::of($mutant->mutation()->diff());

        return [
            'id' => $mutant->id()->value(),
            'mutatorName' => Mutator::short($mutant->mutation()->mutator()),
            'replacement' => $change->added(),
            'location' => ['start' => $columns->start(), 'end' => $columns->end()],
            'status' => self::statusOf($judged->judgement(), $uncovered),
            'statusReason' => $reason instanceof Reason
                ? sprintf('%s: %s', Label::of($judged->judgement()), $reason->text())
                : Label::of($judged->judgement()),
            'description' => self::description($judged),
        ];
    }

    /** Judging tests, the hint and the reproduce command, one to a line. */
    private static function description(JudgedMutant $judged): string
    {
        $tests = [];

        foreach ($judged->tests() as $test) {
            $tests[] = $test->value();
        }

        return implode("\n", [
            ...count($tests) > 0 ? [sprintf('Judged by: %s', implode(', ', $tests))] : [],
            $judged->hint()->text(),
            sprintf('Reproduce: %s', $judged->reproduce()),
        ]);
    }

    /** The viewer status the gate's score treats the same way. */
    private static function statusOf(MutantJudgement $judgement, Uncovered $uncovered): string
    {
        return match ($judgement) {
            MutantJudgement::Killed, MutantJudgement::Errored => 'Killed',
            MutantJudgement::KilledByTimeout => 'Timeout',
            MutantJudgement::Survived,
            MutantJudgement::Unjudged,
            MutantJudgement::Flaky,
            MutantJudgement::TooSlowToJudge => 'Survived',
            MutantJudgement::Uncovered => $uncovered === Uncovered::Exclude ? 'Ignored' : 'NoCoverage',
            MutantJudgement::Ignored, MutantJudgement::IgnoredByMarker => 'Ignored',
        };
    }
}
