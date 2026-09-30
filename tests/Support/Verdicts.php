<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function explode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\Cost;
use NightWorksIO\MutationGate\Core\Cost\Phase;
use NightWorksIO\MutationGate\Core\Cost\RunAccount;
use NightWorksIO\MutationGate\Core\Cost\RunTime;
use NightWorksIO\MutationGate\Core\Cost\RunTimings;
use NightWorksIO\MutationGate\Core\Cost\Savings;
use NightWorksIO\MutationGate\Core\Cost\ShardTiming;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\KillMatrix;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily as Family;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Reach\Reason as Cause;
use NightWorksIO\MutationGate\Core\Reach\Reasons;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Percentage;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Test\TestRow;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\Failure;
use NightWorksIO\MutationGate\Core\Verdict\Failures;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnit;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement as Judged;
use NightWorksIO\MutationGate\Core\Verdict\NewCodeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\NewCodeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

use function sprintf;

/**
 * The verdicts every reporter's tests render: one that fails with a mutant
 * of every judgement, one that passes, and one with nothing to mutate.
 */
final class Verdicts
{
    /** Why the verdict named *cannot judge* could not judge. */
    public const string UNJUDGED = 'Shard 2 wrote no result, so its units are unjudged.';

    public const string MONEY = <<<'PHP'
        <?php

        final class Money
        {
            public function fits(int $amount, int $limit): bool
            {
                if ($amount < $limit) {
                    return true;
                }

                return false;
            }
        }

        PHP;

    /** A diff of `Money.php`'s seventh line. */
    public const string BOUNDARY = <<<'DIFF'
        --- Original
        +++ New
        @@ @@
        -        if ($amount < $limit) {
        +        if ($amount <= $limit) {

        DIFF;

    public const string LESS = 'Pest\\Mutate\\Mutators\\Equality\\LessToLessOrEqual';

    /** A mutant at `<file>:<line>`, with its diff; its runner's status says nothing the reporters read. */
    public static function mutant(string $at, string $mutator, Family $family, string $diff): Mutant
    {
        [$file, $line] = explode(':', $at);

        return Mutant::of(
            MutantId::hash(Path::of($file), $mutator, $diff, (int) $line),
            sprintf('native-%s', $line),
            Location::of(Path::of($file), Line::of((int) $line), Line::of((int) $line)),
            Mutation::of($mutator, $family, $diff),
            MutantStatus::Survived,
            Unmeasured::duration(),
        );
    }

    /** A survivor on a changed line, judged by four tests. */
    public static function survivor(): JudgedMutant
    {
        return JudgedMutant::of(self::mutant('src/Money.php:7', self::LESS, Family::Boundary, self::BOUNDARY), Judged::Survived)
            ->judgedBy(TestIds::of(
                TestId::of('MoneyTest::fits'),
                TestId::of('MoneyTest::refuses'),
                TestId::of('PriceTest::adds'),
                TestId::of('CartTest::totals'),
            ))
            ->within(Reach::nothing(Packages::of(Trees::none()))->withLines(Path::of('src/Money.php'), Lines::of(Line::of(7))));
    }

    /** The mutant of `Money.php`'s ninth line, killed first by `MoneyTest::fits`. */
    public static function killed(): Mutant
    {
        $survivor = self::mutant('src/Money.php:9', 'TrueValue', Family::Literal, self::diff('return true;', 'return false;'));

        return Mutant::of(
            $survivor->id(),
            $survivor->nativeId(),
            $survivor->location(),
            $survivor->mutation(),
            MutantStatus::Killed,
            Unmeasured::duration(),
        )->killedBy(TestIds::of(TestId::of('MoneyTest::fits')));
    }

    /**
     * The coverage of `Money.php` the failing verdict's matrix reads: its
     * seventh line run by `MoneyTest::fits`, `MoneyTest::refuses` and
     * `PriceTest::adds`, its ninth by `MoneyTest::fits` and `PriceTest::adds`,
     * with the runner's names for two of them, one a data set row.
     */
    public static function matrix(MatrixKind $kind): KillMatrix
    {
        $money = Path::of('src/Money.php');
        $fits = TestId::of('MoneyTest::fits');
        $refuses = TestId::of('MoneyTest::refuses');
        $adds = TestId::of('PriceTest::adds');
        $coverage = CoverageMap::empty()
            ->covered($money, Line::of(7), $fits)
            ->covered($money, Line::of(7), $refuses)
            ->covered($money, Line::of(7), $adds)
            ->covered($money, Line::of(9), $fits)
            ->covered($money, Line::of(9), $adds)
            ->timed($fits, Seconds::of(0.25));
        $test = TestName::in(Path::of('tests/Unit/MoneyTest.php'), 'it fits');

        return KillMatrix::of($kind, $coverage)->named(
            TestNames::none()->with($fits, $test)->with($refuses, TestRow::of($test, '"over"')),
        );
    }

    /** Every judgement once, the survivor on a changed line first. */
    public static function everyJudgement(): JudgedMutants
    {
        $flaky = JudgedMutant::of(self::mutant('src/Order.php:3', 'MethodCallRemoval', Family::RemovedCall, self::diff('$this->save($order);', '')), Judged::Flaky);
        $unjudged = self::mutant('src/Order.php:5', 'DecrementInteger', Family::Literal, self::diff('return 3;', 'return 4;'))
            ->because(Reason::that('The run\'s budget ran out before it.'));
        $slow = self::mutant('src/Order.php:8', 'Plus', Family::Arithmetic, self::diff('return $a + $b;', 'return $a - $b;'))
            ->withLimit(Seconds::of(5.0));
        $ignored = self::mutant('src/Log.php:4', 'MethodCallRemoval', Family::RemovedCall, self::diff('$this->log($line);', ''))
            ->because(Reason::that('Logging is asserted in the integration suite'));

        return JudgedMutants::of(
            self::survivor(),
            JudgedMutant::of(self::killed(), Judged::Killed),
            JudgedMutant::of(self::mutant('src/Money.php:12', 'FalseValue', Family::Literal, self::diff('return false;', 'return true;')), Judged::Uncovered),
            $flaky->judgedBy(TestIds::of(TestId::of('OrderTest::saves'))),
            JudgedMutant::of($unjudged, Judged::Unjudged),
            JudgedMutant::of($slow, Judged::TooSlowToJudge),
            JudgedMutant::of(self::mutant('src/Order.php:11', 'Plus', Family::Arithmetic, self::diff('$c = $a + $b;', '$c = $a - $b;')), Judged::KilledByTimeout),
            JudgedMutant::of($ignored, Judged::Ignored),
            JudgedMutant::of(self::mutant('src/Log.php:6', 'Concat', Family::None, self::diff('return $a . $b;', 'return $b . $a;')), Judged::IgnoredByMarker),
            JudgedMutant::of(self::mutant('src/Log.php:9', 'Throw_', Family::Exception, self::diff('throw new Refused();', '')), Judged::Errored),
            JudgedMutant::of(self::mutant('src/Log.php:12', 'ReturnValue', Family::ReturnValue, self::diff('return $x ?? 0;', 'return $x;')), Judged::Survived)
                ->provenEquivalent(),
        );
    }

    /**
     * A change-scoped run that fails: `src` below its floor with a mutant of
     * every judgement, an exempt tree, a tree with nothing to mutate, new code
     * below its floor, a stale ignore, a hot path and the reach's reasons.
     */
    public static function failing(): Verdict
    {
        $root = Package::at(Path::root());
        $units = JudgedUnits::of(
            JudgedUnit::of(Unit::file(Path::of('src/Money.php')), Origin::Run),
            JudgedUnit::of(Unit::file(Path::of('src/Order.php')), Origin::Proved),
            JudgedUnit::of(Unit::held(Path::of('src/Log.php'), Group::named('holds:src/Log.php')), Origin::Carried),
        );
        $src = TreeVerdict::judged(Tree::at(Path::of('src'), Floor::of(80), $root), Floor::of(75.5), $units, self::everyJudgement(), Uncovered::Count)
            ->comparedWith(Score::ofHundredths(4_000));
        $legacy = Tree::at(Path::of('app/Legacy'), Exempt::because('Replaced by the new billing module'), $root);
        $empty = Tree::at(Path::of('src/Empty'), Floor::of(90), $root);
        $newCode = NewCodeVerdict::judged($root, Floor::of(100), JudgedMutants::of(self::survivor()), Uncovered::Count);

        return Verdict::of(TreeVerdicts::of($src, self::bare($legacy), self::bare($empty)))
            ->withNewCode(NewCodeVerdicts::of($newCode))
            ->withReach(Reasons::of(Cause::that('src/Money.php changed, so it is reached.')))
            ->withWarnings(Warnings::of(Warning::that('src/Kernel.php is run by 412 of 430 tests and nothing holds it.')))
            ->withFailures(Failures::of(Failure::that('The ignore of 3f9a1c2b7d04 matched no mutant. Remove it.')));
    }

    /** A full run that passes, with its one mutant killed and its floor able to rise. */
    public static function passing(): Verdict
    {
        $killed = JudgedMutant::of(self::mutant('src/Money.php:9', 'TrueValue', Family::Literal, self::diff('return true;', 'return false;')), Judged::Killed);

        return Verdict::of(TreeVerdicts::of(TreeVerdict::judged(
            Tree::at(Path::of('src'), Floor::of(80), Package::at(Path::root())),
            Unrecorded::floor(),
            JudgedUnits::of(JudgedUnit::of(Unit::file(Path::of('src/Money.php')), Origin::Run)),
            JudgedMutants::of($killed),
            Uncovered::Count,
        )));
    }

    /** 30 days before the stopped clock the reporters' tests read, 2026-09-30T12:00:00Z. */
    public static function monthAgo(): Instant
    {
        return Moment::at('2026-08-31T12:00:00Z');
    }

    /**
     * The failing verdict's run, in two shards: it took 6 minutes of wall
     * time and 14 of runner time, as GitHub measured them; it planned 7 and
     * 15, with 1 minute of setup a job; reach and proofs spared 41 minutes;
     * and against a full one-job run of 1h 41m, 94.5% measured, reach saved
     * 1h 20m and proofs 7m, while sharding cut the wait by 38m and cost 3m of
     * setup.
     */
    public static function account(): RunAccount
    {
        $at = static fn(string $time): Instant => Moment::at(sprintf('2026-09-30T%sZ', $time));
        $timings = RunTimings::of('github:12345/1', RunTime::measured(Seconds::of(360.0), Seconds::of(840.0)))
            ->withPlan(Phase::of($at('11:50:00'), Seconds::of(40.0)))
            ->withShard(ShardTiming::of(1, Phase::of($at('11:51:00'), Seconds::of(20.0)), Phase::of($at('11:51:20'), Seconds::of(200.0))))
            ->withShard(ShardTiming::of(2, Phase::of($at('11:51:05'), Seconds::of(25.0)), Phase::of($at('11:51:30'), Seconds::of(180.0))))
            ->withVerdict(Phase::of($at('11:55:10'), Seconds::of(30.0)));
        $percent = Percentage::inHundredths(9_450);
        $measured = $percent instanceof Percentage ? $percent : Percentage::of(Floor::of(0));

        return RunAccount::none()
            ->withTimings($timings)
            ->withCost(Cost::of(
                RunTime::estimated(Seconds::of(420.0), Seconds::of(900.0)),
                RunTime::measured(Seconds::of(360.0), Seconds::of(840.0)),
                Seconds::of(2_460.0),
                Seconds::of(60.0),
            ))
            ->withSavings(Savings::of(
                Seconds::of(6_060.0),
                $measured,
                Seconds::of(4_800.0),
                Seconds::of(420.0),
            )->sharded(Seconds::of(2_280.0), Seconds::of(180.0)));
    }

    /** A third shard, which a test adds to the account's timings. */
    public static function shard(): ShardTiming
    {
        return ShardTiming::of(
            3,
            Phase::of(Moment::at('2026-09-30T11:51:10Z'), Seconds::of(10.0)),
            Phase::of(Moment::at('2026-09-30T11:51:20Z'), Seconds::of(10.0)),
        );
    }

    /** One of the verdicts above, by its name, for a dataset to list. */
    public static function named(string $name): Verdict
    {
        return match ($name) {
            'failing' => self::failing(),
            'passing' => self::passing(),
            'cut short' => self::passing()->cutShort(),
            'accounted' => self::failing()->withAccount(self::account()),
            'with a matrix' => self::failing()->withMatrix(self::matrix(MatrixKind::FirstKiller)),
            'cannot judge' => self::failing()->withCannotJudge(CannotJudge::because(self::UNJUDGED)),
            default => self::empty(),
        };
    }

    /** A run of one tree with no mutant in it. */
    public static function empty(): Verdict
    {
        return Verdict::of(TreeVerdicts::of(self::bare(Tree::at(Path::of('src'), Floor::of(80), Package::at(Path::root())))));
    }

    /** A verdict over these mutants alone, in one tree held to this floor. */
    public static function of(Floor $floor, JudgedMutant ...$mutants): Verdict
    {
        return Verdict::of(TreeVerdicts::of(TreeVerdict::judged(
            Tree::at(Path::of('src'), $floor, Package::at(Path::root())),
            Unrecorded::floor(),
            JudgedUnits::none(),
            JudgedMutants::of(...$mutants),
            Uncovered::Count,
        )));
    }

    /** A one-line diff, as Pest writes one: a line removed, and the line put in its place where there is one. */
    public static function diff(string $removed, string $added): string
    {
        return $added === ''
            ? sprintf("@@ @@\n-        %s\n", $removed)
            : sprintf("@@ @@\n-        %s\n+        %s\n", $removed, $added);
    }

    private static function bare(Tree $tree): TreeVerdict
    {
        return TreeVerdict::judged($tree, Unrecorded::floor(), JudgedUnits::none(), JudgedMutants::none(), Uncovered::Count);
    }
}
