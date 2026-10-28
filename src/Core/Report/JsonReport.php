<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_map;
use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\GateCommand;
use NightWorksIO\MutationGate\Core\Matrix\KillMatrix;
use NightWorksIO\MutationGate\Core\Matrix\NotFull;
use NightWorksIO\MutationGate\Core\Matrix\SuiteScore;
use NightWorksIO\MutationGate\Core\Matrix\SuiteScores;
use NightWorksIO\MutationGate\Core\Owner\Owner;
use NightWorksIO\MutationGate\Core\Reach\Reason as Cause;
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
use NightWorksIO\MutationGate\Core\Verdict\FailingTests;
use NightWorksIO\MutationGate\Core\Verdict\Failure;
use NightWorksIO\MutationGate\Core\Verdict\JudgedKill;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnit;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\NewCodeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\OwnerFloorVerdict;
use NightWorksIO\MutationGate\Core\Verdict\OwnerVerdict;
use NightWorksIO\MutationGate\Core\Verdict\SecurityVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Verdict\Warning;

/**
 * The gate's own report, `"format": 1`: everything in the verdict, as JSON a
 * program reads. `resources/report.schema.json` describes it, and it is
 * public API (ADR-0009).
 *
 * @phpstan-import-type MutantEntry from MutantJson
 * @phpstan-import-type EstimateEntry from EstimateJson
 * @phpstan-import-type SampleEntry from EstimateJson
 * @phpstan-import-type ProvenanceEntry from ProvenanceJson
 * @phpstan-import-type Run from AccountJson
 * @phpstan-import-type CostEntry from AccountJson
 * @phpstan-import-type Saved from AccountJson
 * @phpstan-import-type PruningEntry from AccountJson
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
 *     estimate?: EstimateEntry,
 *     judgement: string,
 *     said: string,
 *     counts: Numbers,
 *     units: list<UnitEntry>,
 *     mutants: list<string>,
 * }
 * @phpstan-type NewCodeEntry array{
 *     package: string,
 *     floor: float,
 *     score?: float,
 *     judgement: string,
 *     said: string,
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
 *     said: string,
 *     counts: Numbers,
 *     mutants: list<string>,
 * }
 * @phpstan-type OwnerEntry array{
 *     owners: list<string>,
 *     score?: float,
 *     judgement: string,
 *     said: string,
 *     counts: Numbers,
 *     mutants: list<string>,
 * }
 * @phpstan-type OwnerFloorEntry array{
 *     owner: string,
 *     floor: float,
 *     score?: float,
 *     judgement: string,
 *     said: string,
 *     counts: Numbers,
 *     mutants: list<string>,
 * }
 * @phpstan-type SuiteEntry array{
 *     name: string,
 *     covered: int,
 *     killed: int,
 *     score?: float,
 *     exact: bool,
 *     said: string,
 * }
 * @phpstan-type ClusterEntry array{id: string, kind: string, members: list<string>, representative: string}
 * @phpstan-type FailingEntry array{line: string, tests: list<int>, mutants: list<string>}
 * @phpstan-type Report array{
 *     format: int,
 *     judgement: string,
 *     cutShort: bool,
 *     sample?: SampleEntry,
 *     uncovered: string,
 *     score?: float,
 *     counts: Numbers,
 *     trees: list<TreeEntry>,
 *     newCode: list<NewCodeEntry>,
 *     security: list<SecurityEntry>,
 *     owners: list<OwnerEntry>,
 *     ownerFloors: list<OwnerFloorEntry>,
 *     suites: list<SuiteEntry>,
 *     suitesUnscored?: string,
 *     suitesLowerBound?: string,
 *     matrix: string,
 *     tests: list<TestEntry>,
 *     mutants: list<MutantEntry>,
 *     clusters: list<ClusterEntry>,
 *     reach: list<string>,
 *     warnings: list<string>,
 *     failures: list<string>,
 *     cannotJudge: list<string>,
 *     failingUnmutated: list<FailingEntry>,
 *     why: list<string>,
 *     next: list<string>,
 *     provenance: ProvenanceEntry,
 *     run?: Run,
 *     cost?: CostEntry,
 *     savings?: Saved|array{noHistory: true},
 *     pruning?: PruningEntry,
 * }
 */
final readonly class JsonReport
{
    /** The version of this format, which changes only when a reader would misread the new one. */
    public const int FORMAT = 2;

    public static function encode(Verdict $verdict): string
    {
        return JsonText::encode(self::of($verdict));
    }

    /**
     * The report as the data it encodes, which the HTML report renders too,
     * so every reader of a run reads one verdict.
     *
     * @return Report
     */
    public static function of(Verdict $verdict): array
    {
        $overview = Overview::of($verdict);
        $table = TestTable::of($verdict);

        return [
            'format' => self::FORMAT,
            'judgement' => $verdict->judgement()->value,
            'cutShort' => $verdict->wasCutShort(),
            ...EstimateJson::sample($verdict->trees()->sample()),
            'uncovered' => $overview->uncovered()->value,
            ...self::scored($overview->score()),
            'counts' => self::counts($verdict->trees()->mutants()->counts()),
            'trees' => self::each($verdict->trees(), self::tree(...)),
            'newCode' => self::each($verdict->sets()->newCode(), self::newCode(...)),
            'security' => self::each($verdict->sets()->security(), self::security(...)),
            'owners' => self::each($verdict->sets()->owners(), self::owner(...)),
            'ownerFloors' => self::each($verdict->sets()->ownerFloors(), self::ownerFloor(...)),
            ...self::suites(SuiteScores::of($verdict), $verdict->matrix()->whyNotFull(), $overview->gate()),
            'matrix' => $verdict->matrix()->kind()->value,
            'tests' => self::each(
                $table->tests(),
                static fn(TestId $test): array => self::test($verdict->matrix(), $test),
            ),
            'mutants' => self::each(
                $verdict->trees()->mutants(),
                static fn(JudgedMutant|JudgedKill $one): array => MutantJson::of(
                    $one,
                    $verdict->matrix(),
                    $table,
                    $overview->gate(),
                ),
            ),
            'clusters' => self::each($verdict->trees()->clusters(), self::cluster(...)),
            'reach' => self::texts($verdict->reach(), static fn(Cause $reason): string => $reason->text()),
            'warnings' => self::texts($verdict->warnings(), static fn(Warning $warning): string => $warning->text()),
            'failures' => self::texts($verdict->failures(), static fn(Failure $failure): string => $failure->text()),
            'cannotJudge' => self::texts($verdict->obstacles(), static fn(CannotJudge $why): string => $why->why()),
            'failingUnmutated' => self::each(
                $verdict->obstacles()->unmutatedFailures(),
                static fn(FailingTests $failing): array => self::failing($failing, $table),
            ),
            'why' => Reasons::of($verdict),
            'next' => NextSteps::of($verdict),
            'provenance' => ProvenanceJson::of($verdict),
            ...AccountJson::of($verdict),
        ];
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

    /**
     * A set of tests that fails without any mutant: its line, the tests by
     * their places in the table, and the mutants it leaves out, by their ids.
     *
     * @return FailingEntry
     */
    private static function failing(FailingTests $failing, TestTable $table): array
    {
        return [
            'line' => $failing->line(),
            'tests' => $table->placesOf($failing->tests()),
            'mutants' => array_map(
                static fn(JudgedMutant $judged): string => $judged->mutant()->id()->value(),
                $failing->mutants(),
            ),
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
            ...EstimateJson::tree($tree),
            'judgement' => $tree->judgement()->value,
            'said' => SetText::tree($tree),
            'counts' => self::counts($tree->counts()),
            'units' => self::each($tree->units(), self::unit(...)),
            'mutants' => self::ids($tree->mutants()),
        ];
    }

    /**
     * What each suite the PHPUnit config declares alone kills, and why the
     * scores are lower bounds where any is; none, with why, where the runner
     * named no test (ADR-0025).
     *
     * @return array{suites: list<SuiteEntry>, suitesUnscored?: string, suitesLowerBound?: string}
     */
    private static function suites(SuiteScores $scores, NotFull $why, GateCommand $gate): array
    {
        $exact = true;

        foreach ($scores as $score) {
            $exact = $exact && $score->isExact();
        }

        return match (true) {
            ! $scores->arePlaced() && count($scores) > 0 => ['suites' => [], 'suitesUnscored' => SuiteText::unplaced()],
            $exact => ['suites' => self::each($scores, self::suite(...))],
            default => [
                'suites' => self::each($scores, self::suite(...)),
                'suitesLowerBound' => SuiteText::lowerBound($why, $gate),
            ],
        };
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
            'said' => SuiteText::of($score),
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
            'said' => SetText::security($set),
            'counts' => self::counts($set->counts()),
            'mutants' => self::ids($set->mutants()),
        ];
    }

    /** @return OwnerEntry */
    private static function owner(OwnerVerdict $group): array
    {
        return [
            'owners' => array_map(static fn(Owner $owner): string => $owner->value(), [...$group->group()]),
            ...self::scored($group->score()),
            'judgement' => $group->judgement()->value,
            'said' => SetText::owners($group),
            'counts' => self::counts($group->counts()),
            'mutants' => self::ids($group->mutants()),
        ];
    }

    /** @return OwnerFloorEntry */
    private static function ownerFloor(OwnerFloorVerdict $owner): array
    {
        return [
            'owner' => $owner->owner()->value(),
            'floor' => self::percent($owner->floor()),
            ...self::scored($owner->score()),
            'judgement' => $owner->judgement()->value,
            'said' => SetText::ownerFloor($owner),
            'counts' => self::counts($owner->counts()),
            'mutants' => self::ids($owner->mutants()),
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
            'said' => SetText::newCode($set),
            'counts' => self::counts($set->counts()),
            'mutants' => self::ids($set->mutants()),
        ];
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
