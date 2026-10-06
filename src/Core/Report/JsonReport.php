<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function count;

use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\Cluster\Membership;
use NightWorksIO\MutationGate\Core\Cluster\Unclustered;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Matrix\KillMatrix;
use NightWorksIO\MutationGate\Core\Matrix\SuiteScore;
use NightWorksIO\MutationGate\Core\Matrix\SuiteScores;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantRecord;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Reach\Reason as Cause;
use NightWorksIO\MutationGate\Core\Removal\Removable;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestRow;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\Counts;
use NightWorksIO\MutationGate\Core\Verdict\Failure;
use NightWorksIO\MutationGate\Core\Verdict\JudgedKill;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnit;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\NewCodeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\NoFinding;
use NightWorksIO\MutationGate\Core\Verdict\SecurityVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Verdict\Warning;

/**
 * The gate's own report, `"format": 1`: everything in the verdict, as JSON a
 * program reads. `resources/report.schema.json` describes it, and it is
 * public API (ADR-0009, decision 2).
 *
 * @phpstan-import-type RejectionWritten from MutantRecord
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
 * @phpstan-type SecurityEntry array{
 *     package: string,
 *     declared?: float,
 *     baseline?: float,
 *     floor?: float,
 *     score?: float,
 *     raised?: float,
 *     judgement: string,
 *     counts: Numbers,
 *     mutants: list<string>,
 * }
 * @phpstan-type SuiteEntry array{name: string, covered: int, killed: int, score?: float, exact: bool}
 * @phpstan-type MutantEntry array{
 *     id: string,
 *     file: string,
 *     line: int,
 *     end?: int,
 *     mutator: string,
 *     family?: string,
 *     diff?: string,
 *     status: string,
 *     judgement: string,
 *     reason?: string,
 *     rejection?: RejectionWritten,
 *     changedLine: bool,
 *     coveredBy: list<int>,
 *     judgedBy?: list<int>,
 *     killedBy: list<int>,
 *     hint: string,
 *     reproduce: string,
 *     explain: string,
 *     seconds?: float,
 *     limit?: float,
 *     cluster?: string,
 *     removable?: true,
 * }
 * @phpstan-type ClusterEntry array{id: string, kind: string, members: list<string>, representative: string}
 */
final readonly class JsonReport
{
    /** The version of this format, which changes only when a reader would misread the new one. */
    public const int FORMAT = 2;

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
            'counts' => self::counts($verdict->trees()->mutants()->counts()),
            'trees' => self::each($verdict->trees(), self::tree(...)),
            'newCode' => self::each($verdict->sets()->newCode(), self::newCode(...)),
            'security' => self::each($verdict->sets()->security(), self::security(...)),
            ...self::suites(SuiteScores::of($verdict)),
            'matrix' => $verdict->matrix()->kind()->value,
            'tests' => self::each(
                $table->tests(),
                static fn(TestId $test): array => self::test($verdict->matrix(), $test),
            ),
            'mutants' => self::each(
                $verdict->trees()->mutants(),
                static fn(JudgedMutant|JudgedKill $judged): array => self::mutant($judged, $verdict->matrix(), $table),
            ),
            'clusters' => self::each($verdict->trees()->clusters(), self::cluster(...)),
            'reach' => self::texts($verdict->reach(), static fn(Cause $reason): string => $reason->text()),
            'warnings' => self::texts($verdict->warnings(), static fn(Warning $warning): string => $warning->text()),
            'failures' => self::texts($verdict->failures(), static fn(Failure $failure): string => $failure->text()),
            'cannotJudge' => self::texts($verdict->obstacles(), static fn(CannotJudge $why): string => $why->why()),
            ...AccountJson::of($verdict),
        ]);
    }

    /** @return UnitEntry */
    public static function unit(JudgedUnit $unit): array
    {
        $judgedBy = $unit->unit()->judgedBy();

        return [
            'path' => $unit->unit()->path()->value(),
            ...$judgedBy instanceof Group ? ['group' => $judgedBy->name()] : [],
            ...$judgedBy instanceof Filter ? ['filter' => $judgedBy->pattern()] : [],
            'origin' => $unit->origin()->value,
        ];
    }

    /**
     * One mutant, with its tests by their places in the table (ADR-0014, decision 9).
     *
     * @return MutantEntry
     */
    public static function mutant(JudgedMutant|JudgedKill $judged, KillMatrix $matrix, TestTable $table): array
    {
        $mutant = $judged->mutant();
        $end = $mutant->location()->end();
        $covering = $matrix->coveredBy($judged);
        $judgedBy = $table->placesJudging($judged->tests(), $covering);

        return [
            'id' => $mutant->id()->value(),
            'file' => $mutant->location()->file()->value(),
            'line' => $mutant->location()->start()->number(),
            ...$end instanceof Line ? ['end' => $end->number()] : [],
            'mutator' => $mutant->mutator(),
            ...$mutant instanceof Mutant
                ? ['family' => $mutant->mutation()->family()->value, 'diff' => $mutant->mutation()->diff()]
                : [],
            'status' => $mutant->status()->value,
            'judgement' => $judged->judgement()->value,
            ...self::why($mutant),
            'changedLine' => $judged->isOnChangedLine(),
            'coveredBy' => $table->placesOf($covering),
            ...$judgedBy === [] ? [] : ['judgedBy' => $judgedBy],
            'killedBy' => $table->placesOf($judged->mutant()->killers()),
            'hint' => $judged->hint()->text(),
            'reproduce' => $judged->reproduce(),
            'explain' => $judged->explain(),
            ...self::optional($judged),
        ];
    }

    /** @return ClusterEntry */
    public static function cluster(Cluster $cluster): array
    {
        return [
            'id' => $cluster->id()->value(),
            'kind' => $cluster->kind()->value,
            'members' => self::ids(JudgedMutants::of(...$cluster->members())),
            'representative' => $cluster->representative()->mutant()->id()->value(),
        ];
    }

    /**
     * One test the mutants name, as the list of tests holds it once for
     * their `coveredBy` and `killedBy` to point at.
     *
     * @return TestEntry
     */
    public static function test(KillMatrix $matrix, TestId $test): array
    {
        $name = $matrix->names()->nameOf($test);
        $whole = $matrix->names()->testOf($test);
        $seconds = $matrix->secondsOf($test);

        return [
            'id' => $test->value(),
            'name' => $name->value(),
            ...$whole instanceof TestName ? ['file' => $whole->file()->value()] : [],
            ...$name instanceof TestRow ? ['row' => $name->row()] : [],
            ...$seconds instanceof Seconds ? ['seconds' => $seconds->seconds()] : [],
        ];
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

    /**
     * What each suite the PHPUnit config declares alone kills; none, with
     * why, where the runner named no test (ADR-0025, decision 8).
     *
     * @return array{suites: list<SuiteEntry>, suitesUnscored?: string}
     */
    private static function suites(SuiteScores $scores): array
    {
        return $scores->arePlaced() || count($scores) === 0
            ? ['suites' => self::each($scores, self::suite(...))]
            : ['suites' => [], 'suitesUnscored' => SuiteText::unplaced()];
    }

    /** @return SuiteEntry */
    private static function suite(SuiteScore $score): array
    {
        return [
            'name' => $score->suite(),
            'covered' => $score->covered(),
            'killed' => $score->killed(),
            ...self::scored($score->score()),
            'exact' => $score->isExact(),
        ];
    }

    /** @return SecurityEntry */
    private static function security(SecurityVerdict $set): array
    {
        $declared = $set->declared();
        $baseline = $set->baseline();
        $floor = $set->floor();
        $raised = $set->raised();

        return [
            'package' => $set->package()->path()->value(),
            ...$declared instanceof Floor ? ['declared' => self::percent($declared)] : [],
            ...$baseline instanceof Floor ? ['baseline' => self::percent($baseline)] : [],
            ...$floor instanceof Floor ? ['floor' => self::percent($floor)] : [],
            ...self::scored($set->score()),
            ...$raised instanceof Floor ? ['raised' => self::percent($raised)] : [],
            'judgement' => $set->judgement()->value,
            'counts' => self::counts($set->counts()),
            'mutants' => self::ids($set->mutants()),
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

    /**
     * What a mutant's entry holds only where it applies: how long it ran,
     * the limit it ran under, its cluster, and whether its callee may be
     * deleted.
     *
     * @return array{seconds?: float, limit?: float, cluster?: string, removable?: true}
     */
    private static function optional(JudgedMutant|JudgedKill $judged): array
    {
        $duration = $judged->mutant()->duration();
        $limit = $judged->mutant()->limit();
        $cluster = $judged instanceof JudgedMutant ? $judged->cluster() : Unclustered::mutant();
        $finding = $judged instanceof JudgedMutant ? $judged->finding() : NoFinding::survivor();

        return [
            ...$duration instanceof Seconds ? ['seconds' => $duration->seconds()] : [],
            ...$limit instanceof Seconds ? ['limit' => $limit->seconds()] : [],
            ...$cluster instanceof Membership ? ['cluster' => $cluster->id()->value()] : [],
            ...$finding instanceof Removable ? ['removable' => true] : [],
        ];
    }

    /**
     * Why the mutant stands as it does, where its record says: the reason
     * its runner gave, and the rejection of the analyser that killed it.
     *
     * @return array{reason?: string, rejection?: RejectionWritten}
     */
    private static function why(Mutant|ProvedKill $mutant): array
    {
        $reason = $mutant->reason();

        return match (true) {
            $reason instanceof Reason => ['reason' => $reason->text()],
            $reason instanceof Rejection => [MutantRecord::REJECTION => MutantRecord::rejection($reason)],
            default => [],
        };
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
