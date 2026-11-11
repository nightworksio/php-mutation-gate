<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\DecidingConfig;
use NightWorksIO\MutationGate\Cli\Flow\Ledgers;
use NightWorksIO\MutationGate\Cli\Flow\Recorded;
use NightWorksIO\MutationGate\Cli\Flow\Setup;
use NightWorksIO\MutationGate\Config\Budget;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserHistories;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserHistory;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\CheckTime;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Cost\CostBasis;
use NightWorksIO\MutationGate\Core\Cost\Estimated;
use NightWorksIO\MutationGate\Core\Cost\FirstRun;
use NightWorksIO\MutationGate\Core\Cost\MutatorTally;
use NightWorksIO\MutationGate\Core\Cost\Phase;
use NightWorksIO\MutationGate\Core\Cost\RunTime;
use NightWorksIO\MutationGate\Core\Cost\RunTimings;
use NightWorksIO\MutationGate\Core\Cost\ShardsAtOnce;
use NightWorksIO\MutationGate\Core\Cost\ShardTiming;
use NightWorksIO\MutationGate\Core\Cost\StepTimes;
use NightWorksIO\MutationGate\Core\Cost\Untimed;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\GateCommand;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\RunnerMutatorName;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\Ranking;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Proof\KillPace;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Measurement;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Timing;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Subsumption\Subsumption;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Port\CostModel;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Tests\Fakes\CostModelFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\StaticCheckerFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\LedgerRead;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\PeakMemoryFake;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\RecordedRuns;
use NightWorksIO\MutationGate\Tests\Support\RecordingChecker;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use NightWorksIO\MutationGate\Tests\Support\Subsumed;
use NightWorksIO\MutationGate\Tests\Support\Tallies;
use NightWorksIO\MutationGate\Tests\Support\TickingClock;

afterEach(function (): void {
    Scratch::sweep();
});

$map = static fn(): CoverageMap => RecordedRuns::map();
$run = static fn(Plan $plan): Run => RecordedRuns::run($plan);
$ledgers = static fn(ProofStore $store, Plan $plan, Writing $writing = Writing::Auto): Ledgers => RecordedRuns::ledgers($store, $plan, $writing);

it('tallies what each mutator\'s mutants the shards ran cost and came to, as the verdict judged them, adding to what the ledger held', function () use (
    $map,
    $run,
    $ledgers,
): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::twoShards();
    $results = RecordedRuns::ran($project, ScriptedRunner::fixture(), $map());
    $held = Tallies::of('pest', 'Held', 1, 1, 0, 0, 1.0);
    $store->write(Scope::branch('main'), Ledger::empty()->withLearned(Tallies::holding($held)));
    $spent = 0.0;
    $made = 0;

    foreach ($results->shards() as [, $result, $mutated]) {
        $spent += $result->measured()->spent()->seconds();
        $made += count($mutated->mutants());
    }

    new Recorded(adapters: Flows::adapters($project, [], $store), settings: Flows::settings())
        ->write($plan, $results, RecordedRuns::judged($results), $ledgers($store, $plan), $run($plan), CannotTell::because('It failed.'), RecordedRuns::lastRun());
    $tallies = LedgerRead::ledger($store->read(Scope::branch('main')))->learned()->tallies();
    $tallied = array_sum(array_map(static fn(MutatorTally $tally): int => $tally->mutants(), [...$tallies->costliest()]));

    expect($made)->toBeGreaterThan(0)
        ->and($tallied)->toBe($made + 1)
        ->and($tallies->of(Name::of('pest'), RunnerMutatorName::of('Held')))->toEqual($held)
        ->and($tallies->seconds()->seconds())->toEqualWithDelta($spent + 1.0, 0.000001);
});

it('adds the time each analyser\'s checks of the shards\' survivors took to what the ledger held of it', function () use (
    $map,
    $run,
    $ledgers,
): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::twoShards();
    $identity = AnalyserIdentity::of('fake', '1.0.0', Digest::sha256Of('{}'));
    $held = AnalyserHistory::of('fake')->withTime(CheckTime::of(3, Seconds::of(1.5)));
    $store->write(Scope::branch('main'), Ledger::empty()->withLearned(AnalyserHistories::none()->with($held)));
    $checker = new RecordingChecker(new StaticCheckerFake($identity, Findings::none(), []), $project);
    $results = RecordedRuns::ranWith($plan, $project, ScriptedRunner::fixture(), $map(), Flows::settings(), Flows::setup(), $checker);

    new Recorded(adapters: Flows::adapters($project, [], $store), settings: Flows::settings())->write(
        $plan,
        $results,
        JudgedMutants::none(),
        $ledgers($store, $plan),
        $run($plan),
        CannotTell::because('It failed.'),
        RecordedRuns::lastRun(),
    );

    expect(LedgerRead::ledger($store->read(Scope::branch('main')))->learned()->analysers()->of($identity)->time())
        ->toEqual(CheckTime::of(5, Seconds::of(1.5)));
});

it('learns what each shard cost of its units, timed by the map it was handed', function () use (
    $map,
    $run,
    $ledgers,
): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::twoShards();
    $costs = new class implements CostModel {
        /** @var list<array{Units, int, CoverageMap}> */
        private array $learned = [];

        /** @return list<array{Units, int, CoverageMap}> the units, the count of mutants and the map of each lesson */
        public function learned(): array
        {
            return $this->learned;
        }

        public function cost(Unit $unit, Timings $learned, FirstRun $firstRun): Estimated
        {
            return Estimated::of(Seconds::of(1.0), CostBasis::Guessed);
        }

        public function learn(Units $units, Mutants $mutants, CoverageMap $coverage, Measurement $measured): Timings
        {
            $this->learned[] = [$units, count($mutants), $coverage];

            return new CostModelFake(Seconds::of(1.0))->learn($units, $mutants, $coverage, $measured);
        }
    };

    new Recorded(adapters: Flows::adapters($project, [], $store, $costs), settings: Flows::settings())->write(
        $plan,
        RecordedRuns::ran($project, ScriptedRunner::fixture(), $map()),
        JudgedMutants::none(),
        $ledgers($store, $plan),
        $run($plan),
        CannotTell::because('It failed.'),
        RecordedRuns::lastRun(),
    );

    expect($costs->learned())->toEqual([
        [Units::of(Planned::money()), 4, $map()->onlyFor(Paths::of(Path::of('src/Money.php')))],
        [Units::of(Planned::held()), 1, $map()->onlyFor(Paths::none())],
    ]);
});

it('learns nothing of a unit a shard\'s budget ran out before', function () use ($map, $run, $ledgers): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::oneShard();
    $setup = new Setup(
        Absent::setting(),
        Version::of('nightworksio/mutation-gate', '1.0.0', 'gate'),
        Digest::sha256Of('installed'),
        new TickingClock('2026-09-30T12:00:00+00:00', 10),
        new PeakMemoryFake(NotGiven::value()),
        DecidingConfig::unread(),
        GateCommand::installed(),
    );
    $results = RecordedRuns::ranWith($plan, $project, ScriptedRunner::fixture(), $map(), Flows::settings(Budget::of('45s')), $setup);

    new Recorded(adapters: Flows::adapters($project, [], $store), settings: Flows::settings())->write(
        $plan,
        $results,
        JudgedMutants::none(),
        $ledgers($store, $plan),
        $run($plan),
        CannotTell::because('It failed.'),
        RecordedRuns::lastRun(),
    );
    $timings = LedgerRead::ledger($store->read(Scope::branch('main')))->timings();

    expect($timings->secondsFor(Path::of('src/Money.php')))->toBeInstanceOf(Seconds::class)
        ->and($timings->secondsFor(Path::of('src/Held.php')))->not->toBeInstanceOf(Seconds::class);
});

it('learns each killed mutant\'s first killer in its function, and forgets functions of files gone', function () use (
    $map,
    $run,
    $ledgers,
): void {
    $project = Flows::project();
    Scratch::write($project, 'src/Money.php', <<<'PHP'
        <?php

        final class Money
        {
            public function add(): int
            {
                return 1 + 1;
            }
        }

        PHP);
    $store = new ProofStoreFake();
    $plan = Planned::twoShards();
    $gone = Enclosing::named(Path::of('src/Gone.php'), 'old');
    $store->write(Scope::branch('main'), Ledger::empty()->withLearned(
        KillHistory::none()->withFunction($gone, Ranking::none()->killedBy(TestId::of('GoneTest::old'))),
    ));
    $killed = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', '@@ @@', 0),
        'Plus-7',
        Location::of(Path::of('src/Money.php'), Line::of(7), Line::of(7)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, '@@ @@'),
        MutantStatus::Killed,
        Seconds::of(0.1),
    )->killedBy(TestIds::of(TestId::of('MoneyTest::adds'), TestId::of('MoneyTest::subtracts')));
    $runner = ScriptedRunner::fixture()->answering(Mutants::of($killed), 0);

    new Recorded(adapters: Flows::adapters($project, [], $store), settings: Flows::settings())->write(
        $plan,
        RecordedRuns::ranOf($plan, $project, $runner, $map()),
        JudgedMutants::none(),
        $ledgers($store, $plan),
        $run($plan),
        CannotTell::because('It failed.'),
        RecordedRuns::lastRun(),
    );
    $killers = LedgerRead::ledger($store->read(Scope::branch('main')))->learned()->killers();
    $unseen = MutantId::hash(Path::of('src/Money.php'), 'Minus', '@@ @@', 0);
    $add = Enclosing::named(Path::of('src/Money.php'), 'add');

    expect($killers->likelyKillers($killed->id(), Nameless::code()))
        ->toEqual(TestIds::of(TestId::of('MoneyTest::adds')))
        ->and($killers->likelyKillers($unseen, $add))->toEqual(TestIds::of(TestId::of('MoneyTest::adds')))
        ->and($killers->likelyKillers($unseen, $gone))->toEqual(TestIds::none());
});

it('smooths what a shard teaches of a unit over the timing a ledger held of it from the same runner', function () use (
    $map,
    $run,
    $ledgers,
): void {
    $plan = Planned::twoShards();
    $learnt = static function (Ledger $held) use ($map, $run, $ledgers, $plan): Timing|NotGiven {
        $project = Flows::project();
        $store = new ProofStoreFake();
        $store->write(Scope::branch('main'), $held);
        new Recorded(adapters: Flows::adapters($project, [], $store), settings: Flows::settings())->write(
            $plan,
            RecordedRuns::ran($project, ScriptedRunner::fixture(), $map()),
            JudgedMutants::none(),
            $ledgers($store, $plan),
            $run($plan),
            CannotTell::because('It failed.'),
            RecordedRuns::lastRun(),
        );

        foreach (LedgerRead::ledger($store->read(Scope::branch('main')))->timings() as $timing) {
            if ($timing->unit()->equals(Path::of('src/Money.php'))) {
                return $timing;
            }
        }

        return NotGiven::value();
    };
    $measured = $learnt(Ledger::empty());
    $by = $measured instanceof Timing ? $measured->runner() : '';
    $first = $measured instanceof Timing ? $measured->seconds()->seconds() : -1.0;
    $held = static fn(string $runner): Ledger => Ledger::empty()->withTimings(Timings::of(
        Timing::of(Path::of('src/Money.php'), Seconds::of(100.0), $runner, Moment::at('2026-01-01T00:00:00Z')),
    ));
    $seconds = static fn(Timing|NotGiven $timing): float => $timing instanceof Timing ? $timing->seconds()->seconds() : -1.0;

    expect($seconds($learnt($held($by))))->toEqualWithDelta(Timing::NEWEST * $first + (1 - Timing::NEWEST) * 100.0, 1e-9)
        ->and($seconds($learnt($held('another runner'))))->toEqualWithDelta($first, 1e-9)
        ->and($first)->toBeLessThan(100.0);
});

it('learns each kill\'s pace by the map its shard was handed, and how many shards ran at once where the run was cut into shards', function () use (
    $map,
    $run,
    $ledgers,
): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::twoShards();
    $results = RecordedRuns::ran($project, ScriptedRunner::fixture(), $map());
    $paces = [];

    foreach ($results->shards() as [, , $mutated]) {
        $paces = [...$paces, ...KillPace::ofAll($mutated->mutants(), $map())];
    }

    $shard = static fn(int $id, string $start): ShardTiming => ShardTiming::of(
        $id,
        Phase::of(Moment::at($start), Seconds::of(300.0)),
        StepTimes::none(),
    );
    $timings = RunTimings::of('run', RunTime::measured(Seconds::of(1.0), Seconds::of(1.0)))
        ->withShard($shard(1, '2026-09-30T12:00:00Z'))
        ->withShard($shard(2, '2026-09-30T12:02:00Z'));

    new Recorded(adapters: Flows::adapters($project, [], $store), settings: Flows::settings())->write(
        $plan,
        $results,
        JudgedMutants::none(),
        $ledgers($store, $plan),
        $run($plan),
        CannotTell::because('It failed.'),
        RecordedRuns::lastRun(),
        $timings,
    );
    $limits = LedgerRead::ledger($store->read(Scope::branch('main')))->learned()->limits();

    expect($paces)->not->toBe([])
        ->and([...$limits->kills()])->toEqual($paces)
        ->and([...$limits->concurrency()])->toEqual([ShardsAtOnce::of(2)]);
});

it('learns no concurrency from a run it was given no timings of, or one not cut into shards', function () use ($map, $run, $ledgers): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::twoShards();
    $results = RecordedRuns::ran($project, ScriptedRunner::fixture(), $map());
    $single = RunTimings::of('run', RunTime::measured(Seconds::of(1.0), Seconds::of(1.0)))
        ->withShard(ShardTiming::of(1, Phase::of(Moment::at('2026-09-30T12:00:00Z'), Seconds::of(60.0)), StepTimes::none()));

    foreach ([Untimed::run(), $single] as $timings) {
        new Recorded(adapters: Flows::adapters($project, [], $store), settings: Flows::settings())->write(
            $plan,
            $results,
            JudgedMutants::none(),
            $ledgers($store, $plan),
            $run($plan),
            CannotTell::because('It failed.'),
            RecordedRuns::lastRun(),
            $timings,
        );
    }

    expect(LedgerRead::ledger($store->read(Scope::branch('main')))->learned()->limits()->runCount())->toBe(0);
});

it('learns which mutant subsumes which from a run that records every killer, and nothing from one that records the first', function (MatrixKind $matrix, bool $learns) use (
    $map,
    $run,
    $ledgers,
): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::oneShard();
    $plan = $plan->briefed($plan->briefing()->recording($matrix));
    $by = Subsumed::mutant('src/Money.php', 7, Subsumed::diff('a', 'b'), MutantStatus::Killed, 'MoneyTest::adds');
    $of = Subsumed::mutant('src/Money.php', 7, Subsumed::diff('a', 'c'), MutantStatus::Killed, 'MoneyTest::adds', 'MoneyTest::subtracts');
    $runner = ScriptedRunner::fixture()->answering(Mutants::of($by, $of), 0);

    new Recorded(adapters: Flows::adapters($project, [], $store), settings: Flows::settings())->write(
        $plan,
        RecordedRuns::ranOf($plan, $project, $runner, $map()),
        JudgedMutants::none(),
        $ledgers($store, $plan),
        $run($plan),
        CannotTell::because('It failed.'),
        RecordedRuns::lastRun(),
    );

    expect(LedgerRead::ledger($store->read(Scope::branch('main')))->learned()->subsumption())
        ->toEqual($learns ? Subsumption::learned($by, $of) : Subsumption::none());
})->with([
    'every killer' => [MatrixKind::Full, true],
    'the first killer' => [MatrixKind::FirstKiller, false],
]);
