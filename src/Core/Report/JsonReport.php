<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Matrix\KillMatrix;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Reach\Reason as Cause;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestRow;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\Counts;
use NightWorksIO\MutationGate\Core\Verdict\Failure;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnit;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\NewCodeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Verdict\Warning;

/**
 * The gate's own report, `"format": 1`: everything in the verdict, as JSON a
 * program reads. `resources/report.schema.json` describes it, and it is
 * public API (ADR-0009, decision 2).
 *
 * @phpstan-type Numbers array<string, int>
 * @phpstan-type TestEntry array{id: string, name: string, file?: string, row?: string, seconds?: float}
 * @phpstan-type UnitEntry array{path: string, group?: string, filter?: string, origin: string}
 * @phpstan-type TreeEntry array{
 *     path: string,
 *     package: string,
 *     declared?: float,
 *     exempt?: string,
 *     baseline?: float,
 *     floor?: float,
 *     score?: float,
 *     base?: float,
 *     raised?: float,
 *     judgement: string,
 *     counts: Numbers,
 *     units: list<UnitEntry>,
 *     mutants: list<string>,
 * }
 * @phpstan-type NewCodeEntry array{
 *     package: string,
 *     floor: float,
 *     score?: float,
 *     judgement: string,
 *     counts: Numbers,
 *     mutants: list<string>,
 * }
 * @phpstan-type MutantEntry array{
 *     id: string,
 *     file: string,
 *     line: int,
 *     end?: int,
 *     mutator: string,
 *     family: string,
 *     diff: string,
 *     status: string,
 *     judgement: string,
 *     reason?: string,
 *     changedLine: bool,
 *     tests: list<string>,
 *     coveredBy: list<int>,
 *     killedBy: list<int>,
 *     hint: string,
 *     reproduce: string,
 *     explain: string,
 *     seconds?: float,
 *     limit?: float,
 * }
 */
final readonly class JsonReport
{
    /** The version of this format, which changes only when a reader would misread the new one. */
    public const int FORMAT = 1;

    public static function encode(Verdict $verdict): string
    {
        $overview = Overview::of($verdict);
        $table = TestTable::of($verdict);

        return JsonText::encode([
            'format' => self::FORMAT,
            'judgement' => $verdict->judgement()->value,
            'cutShort' => $verdict->wasCutShort(),
            'uncovered' => $overview->uncovered()->value,
            ...self::scored($overview->score()),
            'counts' => self::counts($verdict->mutants()->counts()),
            'trees' => self::each($verdict->trees(), self::tree(...)),
            'newCode' => self::each($verdict->newCode(), self::newCode(...)),
            'matrix' => $verdict->matrix()->kind()->value,
            'tests' => self::tests($verdict, $table),
            'mutants' => self::each(
                $verdict->mutants(),
                static fn(JudgedMutant $judged): array => self::mutant($judged, $verdict->matrix(), $table),
            ),
            'reach' => self::texts($verdict->reach(), static fn(Cause $reason): string => $reason->text()),
            'warnings' => self::texts($verdict->warnings(), static fn(Warning $warning): string => $warning->text()),
            'failures' => self::texts($verdict->failures(), static fn(Failure $failure): string => $failure->text()),
            ...AccountJson::of($verdict),
        ]);
    }

    /** @return TreeEntry */
    private static function tree(TreeVerdict $tree): array
    {
        $declared = $tree->tree()->declared();
        $baseline = $tree->baseline();
        $floor = $tree->floor();
        $base = $tree->base();
        $raised = $tree->raised();

        return [
            'path' => $tree->tree()->path()->value(),
            'package' => $tree->tree()->package()->path()->value(),
            ...$declared instanceof Floor ? ['declared' => self::percent($declared)] : [],
            ...$declared instanceof Exempt ? ['exempt' => $declared->reason()] : [],
            ...$baseline instanceof Floor ? ['baseline' => self::percent($baseline)] : [],
            ...$floor instanceof Floor ? ['floor' => self::percent($floor)] : [],
            ...self::scored($tree->score()),
            ...$base instanceof Score ? ['base' => self::percent($base)] : [],
            ...$raised instanceof Floor ? ['raised' => self::percent($raised)] : [],
            'judgement' => $tree->judgement()->value,
            'counts' => self::counts($tree->counts()),
            'units' => self::each($tree->units(), self::unit(...)),
            'mutants' => self::ids($tree->mutants()),
        ];
    }

    /** @return NewCodeEntry */
    private static function newCode(NewCodeVerdict $set): array
    {
        return [
            'package' => $set->package()->path()->value(),
            'floor' => self::percent($set->floor()),
            ...self::scored($set->score()),
            'judgement' => $set->judgement()->value,
            'counts' => self::counts($set->counts()),
            'mutants' => self::ids($set->mutants()),
        ];
    }

    /** @return UnitEntry */
    private static function unit(JudgedUnit $unit): array
    {
        $judgedBy = $unit->unit()->judgedBy();

        return [
            'path' => $unit->unit()->path()->value(),
            ...$judgedBy instanceof Group ? ['group' => $judgedBy->name()] : [],
            ...$judgedBy instanceof Filter ? ['filter' => $judgedBy->pattern()] : [],
            'origin' => $unit->origin()->value,
        ];
    }

    /** @return MutantEntry */
    private static function mutant(JudgedMutant $judged, KillMatrix $matrix, TestTable $table): array
    {
        $mutant = $judged->mutant();
        $end = $mutant->location()->end();
        $duration = $mutant->duration();
        $limit = $mutant->limit();
        $reason = $mutant->reason();
        $tests = [];

        foreach ($judged->tests() as $test) {
            $tests[] = $test->value();
        }

        return [
            'id' => $mutant->id()->value(),
            'file' => $mutant->location()->file()->value(),
            'line' => $mutant->location()->start()->number(),
            ...$end instanceof Line ? ['end' => $end->number()] : [],
            'mutator' => $mutant->mutation()->mutator(),
            'family' => $mutant->mutation()->family()->value,
            'diff' => $mutant->mutation()->diff(),
            'status' => $mutant->status()->value,
            'judgement' => $judged->judgement()->value,
            ...$reason instanceof Reason ? ['reason' => $reason->text()] : [],
            'changedLine' => $judged->isOnChangedLine(),
            'tests' => $tests,
            'coveredBy' => $table->placesOf($matrix->coveredBy($judged)),
            'killedBy' => $table->placesOf($judged->mutant()->killers()),
            'hint' => $judged->hint()->text(),
            'reproduce' => $judged->reproduce(),
            'explain' => $judged->explain(),
            ...$duration instanceof Seconds ? ['seconds' => $duration->seconds()] : [],
            ...$limit instanceof Seconds ? ['limit' => $limit->seconds()] : [],
        ];
    }

    /**
     * Every test the mutants name, once, in the places their `coveredBy` and `killedBy` point at.
     *
     * @return list<TestEntry>
     */
    private static function tests(Verdict $verdict, TestTable $table): array
    {
        $entries = [];

        foreach ($table->tests() as $test) {
            $name = $verdict->matrix()->names()->nameOf($test);
            $whole = $verdict->matrix()->names()->testOf($test);
            $seconds = $verdict->matrix()->secondsOf($test);
            $entries[] = [
                'id' => $test->value(),
                'name' => $name->value(),
                ...$whole instanceof TestName ? ['file' => $whole->file()->value()] : [],
                ...$name instanceof TestRow ? ['row' => $name->row()] : [],
                ...$seconds instanceof Seconds ? ['seconds' => $seconds->seconds()] : [],
            ];
        }

        return $entries;
    }

    /** @return Numbers */
    private static function counts(Counts $counts): array
    {
        $numbers = [];

        foreach (MutantJudgement::cases() as $judgement) {
            $numbers[$judgement->value] = $counts->number($judgement);
        }

        return $numbers;
    }

    /** @return array{score?: float} the score, where there is one */
    private static function scored(Score|NothingToMutate $score): array
    {
        return $score instanceof Score ? ['score' => self::percent($score)] : [];
    }

    private static function percent(Score|Floor $value): float
    {
        return $value->percent();
    }

    /** @return list<string> */
    private static function ids(JudgedMutants $mutants): array
    {
        $ids = [];

        foreach ($mutants as $mutant) {
            $ids[] = $mutant->mutant()->id()->value();
        }

        return $ids;
    }

    /**
     * @template T
     * @template E
     *
     * @param  iterable<T>      $items
     * @param  callable(T): E   $encode
     * @return list<E>
     */
    private static function each(iterable $items, callable $encode): array
    {
        $encoded = [];

        foreach ($items as $item) {
            $encoded[] = $encode($item);
        }

        return $encoded;
    }

    /**
     * @template T
     *
     * @param  iterable<T>           $items
     * @param  callable(T): string   $text
     * @return list<string>
     */
    private static function texts(iterable $items, callable $text): array
    {
        $texts = [];

        foreach ($items as $item) {
            $texts[] = $text($item);
        }

        return $texts;
    }
}
