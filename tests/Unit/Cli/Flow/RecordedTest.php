<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\DecidingConfig;
use NightWorksIO\MutationGate\Cli\Flow\Handoff;
use NightWorksIO\MutationGate\Cli\Flow\Ledgers;
use NightWorksIO\MutationGate\Cli\Flow\Recorded;
use NightWorksIO\MutationGate\Cli\Flow\Results;
use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\Setup;
use NightWorksIO\MutationGate\Cli\Flow\Standing;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Config\Budget;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserHistories;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserHistory;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\CheckTime;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Cost\CostBasis;
use NightWorksIO\MutationGate\Core\Cost\Estimated;
use NightWorksIO\MutationGate\Core\Cost\FirstRun;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\Ranking;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Bases;
use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\Inputs;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\LastRun;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Measurement;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\ReadsOnly;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\RunProfile;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\ScopeRuns;
use NightWorksIO\MutationGate\Core\Proof\Timing;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Proof\Undigested;
use NightWorksIO\MutationGate\Core\Proof\Unkeyed;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\CostModel;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Tests\Fakes\CostModelFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\StaticCheckerFake;
use NightWorksIO\MutationGate\Tests\Support\CoverageAsked;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\HandedMaps;
use NightWorksIO\MutationGate\Tests\Support\Judged;
use NightWorksIO\MutationGate\Tests\Support\JudgedCommits;
use NightWorksIO\MutationGate\Tests\Support\LedgerRead;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\PeakMemoryFake;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\RecordingChecker;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use NightWorksIO\MutationGate\Tests\Support\TickingClock;

/** The last run a verdict hands the ledger it writes. */
function recordedLastRun(): LastRun
{
    return LastRun::of(JudgedCommits::of('5eeca8f0a1b2c3d4e5f60718293a4b5c6d7e8f90'), 'mutation-gate', RunProfile::standard());
}

afterEach(function (): void {
    Scratch::sweep();
});

/** The map the plan hands every shard. */
$map = static fn(): CoverageMap => CoverageMap::empty()
    ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('MoneyTest::adds'))
    ->timed(TestId::of('MoneyTest::adds'), Seconds::of(0.5));

/** The run that established the proofs, at the plan's base. */
$run = static fn(Plan $plan): Run => Run::of('local:now', Moment::at('2026-09-30T12:00:00Z'), $plan->base());

/**
 * Every shard of the two-shard plan run in a project, each handed the map, and
 * the results read back.
 */
function recordedRan(string $project, ScriptedRunner $runner, CoverageMap $map): Results
{
    return recordedRanOf(Planned::twoShards(), $project, $runner, $map);
}

/** Every shard of a plan run in a project, each handed the map, and the results read back. */
function recordedRanOf(Plan $plan, string $project, ScriptedRunner $runner, CoverageMap $map): Results
{
    return recordedRanWith($plan, $project, $runner, $map, Flows::settings(), Flows::setup());
}

/**
 * Every shard of a plan run in a project on these settings and this setup,
 * each handed the map, with these ports besides, and the results read back.
 */
function recordedRanWith(
    Plan $plan,
    string $project,
    ScriptedRunner $runner,
    CoverageMap $map,
    Settings $settings,
    Setup $setup,
    object ...$ports,
): Results {
    new Handoff(Directory::at($project), HandedMaps::limits())->write($plan, $map, KillHistory::none(), Unplaced::map());
    new Running(Flows::adapters($project, [], $runner, ...$ports), $settings, $setup)->runAll($plan, Workspace::results());
    $results = Results::read($plan, Workspace::results(), Directory::at($project));

    return $results instanceof Results ? $results : throw new RuntimeException($results->why());
}

/** Every shard of a plan run in a project whose held unit's tests miss lines of it, and the results read back. */
function recordedRanMissing(Plan $plan, string $project, CoverageMap $map): Results
{
    new Handoff(Directory::at($project), HandedMaps::limits())->write($plan, $map, KillHistory::none(), Unplaced::map());
    $adapters = Flows::adapters($project, [], new CoverageAsked(ScriptedRunner::fixture(), CoverageMap::empty()));
    new Running($adapters, Flows::settings(), Flows::setup())->runAll($plan, Workspace::results());
    $results = Results::read($plan, Workspace::results(), Directory::at($project));

    return $results instanceof Results ? $results : throw new RuntimeException($results->why());
}

$ledgers = static fn(ProofStore $store, Plan $plan, Writing $writing = Writing::Auto): Ledgers => Ledgers::read(
    $store,
    Standing::planned($plan),
    $writing,
);

it('writes a proof of every unit that ran to the end, at the plan\'s base, and what each shard cost', function () use (
    $map,
    $run,
    $ledgers,
): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::twoShards();
    $results = recordedRan($project, ScriptedRunner::fixture(), $map());

    $written = new Recorded(settings: Flows::settings(), adapters: Flows::adapters($project, [], $store))
        ->write($plan, $results, $ledgers($store, $plan), $run($plan), CannotTell::because('It failed.'), recordedLastRun());
    $ledger = LedgerRead::ledger($store->read(Scope::branch('main')));
    $proof = $ledger->proofs()->proofFor(Digest::sha256Of('money'));

    expect($written)->toEqual(Written::to('memory:refs/heads/main'))
        ->and($ledger->bases())->toEqual(Bases::of($plan->base()))
        ->and(count($ledger->proofs()))->toBe(2)
        ->and($proof instanceof Proof ? [$proof->unit(), $proof->run(), count($proof->reported())] : $proof)
        ->toEqual([Path::of('src/Money.php'), $run($plan), 4])
        ->and($ledger->proofs()->has(Digest::sha256Of('held')))->toBeTrue()
        ->and(count($ledger->timings()))->toBe(2)
        ->and($ledger->runs()->passed())->toBeInstanceOf(CannotTell::class);
});

it('writes the proofs of a narrowed run, and teaches the cost model nothing from it', function (Narrowing $narrowing) use (
    $map,
    $run,
    $ledgers,
): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::twoShards();
    $results = recordedRan($project, ScriptedRunner::fixture(), $map());

    new Recorded(settings: Flows::settings(), adapters: Flows::adapters($project, [], $store, $narrowing))
        ->write($plan, $results, $ledgers($store, $plan), $run($plan), CannotTell::because('It failed.'), recordedLastRun());
    $ledger = LedgerRead::ledger($store->read(Scope::branch('main')));

    expect(count($ledger->proofs()))->toBe(2)
        ->and(count($ledger->timings()))->toBe(0);
})->with([
    'to the security mutators' => [Narrowing::none()->toMutators(Mutators::named('Plus'))],
    'to one suite' => [Narrowing::none()->toSuite(SuiteName::of('unit'))],
]);

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
    $results = recordedRanWith($plan, $project, ScriptedRunner::fixture(), $map(), Flows::settings(), Flows::setup(), $checker);

    new Recorded(settings: Flows::settings(), adapters: Flows::adapters($project, [], $store))->write(
        $plan,
        $results,
        $ledgers($store, $plan),
        $run($plan),
        CannotTell::because('It failed.'),
        recordedLastRun(),
    );

    expect(LedgerRead::ledger($store->read(Scope::branch('main')))->analysers()->of($identity)->time())
        ->toEqual(CheckTime::of(5, Seconds::of(1.5)));
});

it('records the commit that passed, under its check, with how many of its own proofs it used', function () use (
    $map,
    $run,
    $ledgers,
): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::twoShards();
    $passed = Passed::of(Revision::ref(Flows::HEAD), 'mutation / verdict', 2);

    $results = recordedRan($project, ScriptedRunner::fixture(), $map());

    new Recorded(settings: Flows::settings(), adapters: Flows::adapters($project, [], $store))
        ->write($plan, $results, $ledgers($store, $plan), $run($plan), $passed, recordedLastRun());

    expect(LedgerRead::ledger($store->read(Scope::branch('main')))->runs()->passed())->toEqual($passed);
});

it('keeps the ledger it read, adding to it', function () use ($map, $run, $ledgers): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::twoShards();
    $earlier = Proof::of(Digest::sha256Of('earlier'), Path::of('src/Gone.php'), Mutants::none(), $run($plan));
    $store->write(Scope::branch('main'), Ledger::empty()->withProof($earlier));

    new Recorded(settings: Flows::settings(), adapters: Flows::adapters($project, [], $store))->write(
        $plan,
        recordedRan($project, ScriptedRunner::fixture(), $map()),
        $ledgers($store, $plan),
        $run($plan),
        CannotTell::because('It failed.'),
        recordedLastRun(),
    );

    expect(LedgerRead::ledger($store->read(Scope::branch('main')))->proofs()->has(Digest::sha256Of('earlier')))->toBeTrue();
});

it('records no proof of a unit with a flaky mutant', function () use ($map, $run, $ledgers): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::twoShards();

    new Recorded(settings: Flows::settings(), adapters: Flows::adapters($project, [], $store))->write(
        $plan,
        recordedRan($project, ScriptedRunner::fixture()->killingAgain(), $map()),
        $ledgers($store, $plan),
        $run($plan),
        CannotTell::because('It failed.'),
        recordedLastRun(),
    );
    $proofs = LedgerRead::ledger($store->read(Scope::branch('main')))->proofs();

    expect(count($proofs))->toBe(0);
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

    new Recorded(settings: Flows::settings(), adapters: Flows::adapters($project, [], $store, $costs))->write(
        $plan,
        recordedRan($project, ScriptedRunner::fixture(), $map()),
        $ledgers($store, $plan),
        $run($plan),
        CannotTell::because('It failed.'),
        recordedLastRun(),
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
    );
    $results = recordedRanWith($plan, $project, ScriptedRunner::fixture(), $map(), Flows::settings(Budget::of('45s')), $setup);

    new Recorded(settings: Flows::settings(), adapters: Flows::adapters($project, [], $store))->write(
        $plan,
        $results,
        $ledgers($store, $plan),
        $run($plan),
        CannotTell::because('It failed.'),
        recordedLastRun(),
    );
    $timings = LedgerRead::ledger($store->read(Scope::branch('main')))->timings();

    expect($timings->secondsFor(Path::of('src/Money.php')))->toBeInstanceOf(Seconds::class)
        ->and($timings->secondsFor(Path::of('src/Held.php')))->not->toBeInstanceOf(Seconds::class);
});

it('cannot judge a shard that was handed no map, and writes nothing', function () use ($map, $run, $ledgers): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::twoShards();
    $results = recordedRan($project, ScriptedRunner::fixture(), $map());
    unlink(sprintf('%s/.mutation-gate/coverage/shard-2/map.json.gz', $project));

    $written = new Recorded(settings: Flows::settings(), adapters: Flows::adapters($project, [], $store))
        ->write($plan, $results, $ledgers($store, $plan), $run($plan), CannotTell::because('It failed.'), recordedLastRun());

    expect($written)->toEqual(new Handoff(Directory::at($project), HandedMaps::limits())->read(ShardId::of(2)))
        ->and($written)->toBeInstanceOf(CannotJudge::class)
        ->and(LedgerRead::ledger($store->read(Scope::branch('main'))))->toEqual(Ledger::empty());
});

it('writes nothing where the run may not write its scope', function (
    Writing $writing,
    RunOn $runOn,
) use ($map, $run, $ledgers): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::twoShards()->on($runOn);
    $read = $ledgers($store, $plan, $writing);
    $results = recordedRan($project, ScriptedRunner::fixture(), $map());

    $written = new Recorded(settings: Flows::settings(), adapters: Flows::adapters($project, [], $store))
        ->write($plan, $results, $read, $run($plan), CannotTell::because('It failed.'), recordedLastRun());

    expect($written)->toEqual($read->access()->writes())
        ->and($written)->toBeInstanceOf(ReadsOnly::class)
        ->and(LedgerRead::ledger($store->read(Scope::branch('main'))))->toEqual(Ledger::empty());
})->with([
    'proofs.write is never' => [Writing::Never, RunOn::at(Scope::branch('main'), Scope::branch('main'))],
    'a detached HEAD' => [Writing::Auto, RunOn::detached(Scope::branch('main'))],
]);

it('says why the store did not write', function () use ($map, $run, $ledgers): void {
    $project = Flows::project();
    $store = new class implements ProofStore {
        public function read(Scope $scope): Ledger
        {
            return Ledger::empty();
        }

        public function write(Scope $scope, Ledger $ledger): NotWritten
        {
            return NotWritten::because('The bucket is gone.');
        }

        public function companion(Scope $scope, Companion $companion): Missing
        {
            return Missing::at(Path::of($companion->value));
        }

        public function keep(Scope $scope, Companion $companion, Contents $bytes): NotWritten
        {
            return NotWritten::because('The bucket is gone.');
        }
    };
    $plan = Planned::twoShards();

    expect(new Recorded(settings: Flows::settings(), adapters: Flows::adapters($project, [], $store))->write(
        $plan,
        recordedRan($project, ScriptedRunner::fixture(), $map()),
        $ledgers($store, $plan),
        $run($plan),
        CannotTell::because('It failed.'),
        recordedLastRun(),
    ))->toEqual(NotWritten::because('The bucket is gone.'));
});

it('records no proof of a unit with no key, and leaves the ledger as it was for it', function () use (
    $map,
    $run,
    $ledgers,
): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $keyed = Keys::none()
        ->with(Path::of('src/Money.php'), Digest::sha256Of('money'))
        ->with(Path::of('src/Held.php'), Unkeyed::because('The runner cannot name its tests.'));
    $plan = Plan::of(
        Revision::ref(Flows::HEAD),
        Digest::sha256Of(Planned::BASE),
        $keyed,
        Shards::of(...Planned::twoShards()),
    )->on(RunOn::at(Scope::branch('main'), Scope::branch('main')));

    new Recorded(settings: Flows::settings(), adapters: Flows::adapters($project, [], $store))->write(
        $plan,
        recordedRanOf($plan, $project, ScriptedRunner::fixture(), $map()),
        $ledgers($store, $plan),
        $run($plan),
        CannotTell::because('It failed.'),
        recordedLastRun(),
    );

    expect(array_map(
        static fn(Proof $proof): string => $proof->unit()->value(),
        [...LedgerRead::ledger($store->read(Scope::branch('main')))->proofs()],
    ))->toBe(['src/Money.php']);
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

    new Recorded(settings: Flows::settings(), adapters: Flows::adapters($project, [], $store))->write(
        $plan,
        recordedRanOf($plan, $project, $runner, $map()),
        $ledgers($store, $plan),
        $run($plan),
        CannotTell::because('It failed.'),
        recordedLastRun(),
    );
    $killers = LedgerRead::ledger($store->read(Scope::branch('main')))->killers();
    $unseen = MutantId::hash(Path::of('src/Money.php'), 'Minus', '@@ @@', 0);
    $add = Enclosing::named(Path::of('src/Money.php'), 'add');

    expect($killers->likelyKillers($killed->id(), Nameless::code()))
        ->toEqual(TestIds::of(TestId::of('MoneyTest::adds')))
        ->and($killers->likelyKillers($unseen, $add))->toEqual(TestIds::of(TestId::of('MoneyTest::adds')))
        ->and($killers->likelyKillers($unseen, $gone))->toEqual(TestIds::none());
});

it('records with each proof its share of the plan\'s digests, with each test file that killed a mutant of it and the commit they were taken at', function () use (
    $map,
    $run,
    $ledgers,
): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $digests = Digests::of(Digest::sha256Of('mutation'))
        ->withSource(Path::of('src/Money.php'), Digest::sha256Of('money source'))
        ->withTest(Path::of('tests/MoneyTest.php'), Digest::sha256Of('money test'))
        ->withTest(Path::of('tests/TaxTest.php'), Digest::sha256Of('tax test'))
        ->takenAt(Revision::ref(str_repeat('c0', 20)));
    $plan = Planned::twoShards()
        ->digesting($digests)
        ->naming(TestNames::none()
            ->with(TestId::of('MoneyTest::adds'), TestName::in(Path::of('tests/MoneyTest.php'), 'adds'))
            ->with(TestId::of('TaxTest::rounds'), TestName::in(Path::of('tests/TaxTest.php'), 'rounds')));
    $killed = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', '@@ @@', 0),
        'Plus-1',
        Location::of(Path::of('src/Money.php'), Line::of(1), Line::of(1)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, '@@ @@'),
        MutantStatus::Killed,
        Seconds::of(0.1),
    )->killedBy(TestIds::of(TestId::of('MoneyTest::adds'), TestId::of('UnnamedTest::runs')));
    $timedOut = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Minus', '@@ @@', 0),
        'Minus-2',
        Location::of(Path::of('src/Money.php'), Line::of(2), Line::of(2)),
        Mutation::of('Minus', MutatorFamily::Arithmetic, '@@ @@'),
        MutantStatus::TimedOut,
        Seconds::of(0.1),
    )->killedBy(TestIds::of(TestId::of('TaxTest::rounds')));
    $results = recordedRanOf($plan, $project, ScriptedRunner::fixture()->answering(Mutants::of($killed, $timedOut), 0), $map());

    new Recorded(settings: Flows::settings(), adapters: Flows::adapters($project, [], $store))
        ->write($plan, $results, $ledgers($store, $plan), $run($plan), CannotTell::because('It failed.'), recordedLastRun());
    $ledger = LedgerRead::ledger($store->read(Scope::branch('main')));
    $money = $ledger->proofs()->proofFor(Digest::sha256Of('money'));
    $held = $ledger->proofs()->proofFor(Digest::sha256Of('held'));

    expect($money instanceof Proof ? $money->inputs() : $money)->toEqual(
        Inputs::of(Digest::sha256Of('money source'), Digest::sha256Of('mutation'))
            ->withTest(Path::of('tests/MoneyTest.php'), Digest::sha256Of('money test'))
            ->takenAt(Revision::ref(str_repeat('c0', 20))),
    )
        ->and($held instanceof Proof ? $held->inputs() : $held)->toEqual(Undigested::proof());
});

it('records no digests where the plan has none', function () use ($map, $run, $ledgers): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::twoShards();

    new Recorded(settings: Flows::settings(), adapters: Flows::adapters($project, [], $store))
        ->write($plan, recordedRan($project, ScriptedRunner::fixture(), $map()), $ledgers($store, $plan), $run($plan), CannotTell::because('It failed.'), recordedLastRun());
    $money = LedgerRead::ledger($store->read(Scope::branch('main')))->proofs()->proofFor(Digest::sha256Of('money'));

    expect($money instanceof Proof ? $money->inputs() : $money)->toEqual(Undigested::proof());
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
        new Recorded(settings: Flows::settings(), adapters: Flows::adapters($project, [], $store))->write(
            $plan,
            recordedRan($project, ScriptedRunner::fixture(), $map()),
            $ledgers($store, $plan),
            $run($plan),
            CannotTell::because('It failed.'),
            recordedLastRun(),
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

it('records the commit it judged as its last run where it judged every unit it considered, keeping what passed', function () use (
    $map,
    $run,
    $ledgers,
): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::twoShards();
    $passed = Passed::of(Revision::ref('206b4e0c1f2a3b4c5d6e7f8091a2b3c4d5e6f708'), 'mutation-gate', 0);
    $store->write(Scope::branch('main'), Ledger::empty()->withRuns(ScopeRuns::none()->passing($passed)));

    new Recorded(settings: Flows::settings(), adapters: Flows::adapters($project, [], $store))->write(
        $plan,
        recordedRan($project, ScriptedRunner::fixture(), $map()),
        $ledgers($store, $plan),
        $run($plan),
        CannotTell::because('It failed.'),
        recordedLastRun(),
    );
    $runs = LedgerRead::ledger($store->read(Scope::branch('main')))->runs();

    expect($runs->lastRun())->toEqual(recordedLastRun())
        ->and($runs->passed())->toEqual($passed);
});

it('clears the last run where it did not judge every unit it considered, so the next run reads its change since the ref', function (string $why) use (
    $map,
    $run,
    $ledgers,
): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::oneShard();
    $older = LastRun::of(JudgedCommits::of('206b4e0c1f2a3b4c5d6e7f8091a2b3c4d5e6f708'), 'mutation-gate', RunProfile::standard());
    $store->write(Scope::branch('main'), Ledger::empty()->withRuns(ScopeRuns::none()->lastRunAt($older)));
    $results = match ($why) {
        'flaky' => recordedRanOf($plan, $project, ScriptedRunner::fixture()->killingAgain(), $map()),
        'budget' => recordedRanWith($plan, $project, ScriptedRunner::fixture()->answering(
            Mutants::of(Judged::mutant('src/Money.php', MutantJudgement::Killed)->mutant()),
            0,
        ), $map(), Flows::settings(Budget::of('45s')), new Setup(
            Absent::setting(),
            Version::of('nightworksio/mutation-gate', '1.0.0', 'gate'),
            Digest::sha256Of('installed'),
            new TickingClock('2026-09-30T12:00:00+00:00', 10),
            new PeakMemoryFake(NotGiven::value()),
            DecidingConfig::unread(),
        )),
        default => recordedRanMissing($plan, $project, $map()),
    };

    new Recorded(settings: Flows::settings(), adapters: Flows::adapters($project, [], $store))->write(
        $plan,
        $results,
        $ledgers($store, $plan),
        $run($plan),
        CannotTell::because('It failed.'),
        recordedLastRun(),
    );

    expect(LedgerRead::ledger($store->read(Scope::branch('main')))->runs()->lastRun())->toBeInstanceOf(CannotTell::class);
})->with([
    'a unit flaky, so it left no proof' => ['flaky'],
    'a budget run out before a unit, every unit it ran judged whole' => ['budget'],
    'a held unit whose tests miss lines of it' => ['misses'],
]);
