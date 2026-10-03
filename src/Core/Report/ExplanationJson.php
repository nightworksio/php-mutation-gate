<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Proof\Recorded;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;

/**
 * `explain --format=json` (ADR-0014, decision 15): what the text says, each
 * under its own key, `"format": 1`. Each mutant is the entry the `json`
 * report gives it, its `coveredBy` and `killedBy` pointing into its own
 * `tests`. Public API (ADR-0011, decision 7), described by
 * `resources/explain.schema.json`.
 *
 * @phpstan-import-type MutantEntry from JsonReport
 *
 * @phpstan-type CoveringEntry array{
 *     id: string,
 *     name: string,
 *     file?: string,
 *     row?: string,
 *     seconds?: float,
 *     outcome: string,
 * }
 * @phpstan-type TakenEntry array{
 *     path: string,
 *     group?: string,
 *     filter?: string,
 *     origin: string,
 *     run?: string,
 *     reach: list<string>,
 * }
 * @phpstan-type HistoryEntry array{status: string, run: string, scope: string, at: string}
 * @phpstan-type ExplainedEntry array{
 *     mutant: MutantEntry,
 *     judgingSeconds?: float,
 *     tests: list<CoveringEntry>,
 *     unit: TakenEntry|array{unknown: string},
 *     history: list<HistoryEntry>,
 * }
 */
final readonly class ExplanationJson
{
    public const int FORMAT = 1;

    public static function of(Explanations $explained): string
    {
        $cluster = $explained->cluster();
        $mutants = [];

        foreach ($explained as $explanation) {
            $mutants[] = self::explained($explanation);
        }

        return JsonText::encode([
            'format' => self::FORMAT,
            ...$cluster instanceof Cluster ? ['cluster' => JsonReport::cluster($cluster)] : [],
            'mutants' => $mutants,
        ]);
    }

    /** @return ExplainedEntry */
    private static function explained(Explanation $explanation): array
    {
        $judged = $explanation->mutant();
        $matrix = $explanation->matrix();
        $table = TestTable::over($matrix, $judged);
        $need = $judged instanceof JudgedMutant ? $judged->mutant()->unmutatedNeed() : Unmeasured::duration();
        $tests = [];

        foreach ($table->tests() as $test) {
            $tests[] = [...JsonReport::test($matrix, $test), 'outcome' => $matrix->outcome($judged, $test)->value];
        }

        $history = [];

        foreach ($explanation->history() as $record) {
            $history[] = self::recorded($record);
        }

        return [
            'mutant' => JsonReport::mutant($judged, $matrix, $table),
            ...$need instanceof Seconds ? ['judgingSeconds' => $need->seconds()] : [],
            'tests' => $tests,
            'unit' => self::unit($explanation),
            'history' => $history,
        ];
    }

    /** @return TakenEntry|array{unknown: string} */
    private static function unit(Explanation $explanation): array
    {
        $unit = $explanation->unit();

        if ($unit instanceof CannotTell) {
            return ['unknown' => $unit->why()];
        }

        $run = $unit->run();
        $reach = [];

        foreach ($explanation->reach() as $reason) {
            $reach[] = $reason->text();
        }

        return [
            ...JsonReport::unit($unit),
            ...$run instanceof Run ? ['run' => $run->id()] : [],
            'reach' => $reach,
        ];
    }

    /** @return HistoryEntry */
    private static function recorded(Recorded $record): array
    {
        return [
            'status' => $record->mutant()->status()->value,
            'run' => $record->proof()->run()->id(),
            'scope' => $record->scope()->ref(),
            'at' => $record->proof()->run()->at()->value(),
        ];
    }
}
