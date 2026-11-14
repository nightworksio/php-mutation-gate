<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\DecidingConfig;
use NightWorksIO\MutationGate\Cli\Flow\Handoff;
use NightWorksIO\MutationGate\Cli\Flow\Ledgers;
use NightWorksIO\MutationGate\Cli\Flow\Recorded;
use NightWorksIO\MutationGate\Cli\Flow\Setup;
use NightWorksIO\MutationGate\Config\Budget;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Cost\MutatorTallies;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\GateCommand;
use NightWorksIO\MutationGate\Core\Heat\FunctionSpan;
use NightWorksIO\MutationGate\Core\Heat\FunctionSpans;
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
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\ReadsOnly;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\RunProfile;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\ScopeRuns;
use NightWorksIO\MutationGate\Core\Proof\Undigested;
use NightWorksIO\MutationGate\Core\Proof\Unkeyed;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Pruning\MutatorNames;
use NightWorksIO\MutationGate\Core\Pruning\Pruned;
use NightWorksIO\MutationGate\Core\Pruning\Survival;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\HandedMaps;
use NightWorksIO\MutationGate\Tests\Support\Judged;
use NightWorksIO\MutationGate\Tests\Support\JudgedCommits;
use NightWorksIO\MutationGate\Tests\Support\LedgerRead;
use NightWorksIO\MutationGate\Tests\Support\PeakMemoryFake;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\RecordedRuns;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use NightWorksIO\MutationGate\Tests\Support\TickingClock;

afterEach(function (): void {
    Scratch::sweep();
});

$map = static fn(): CoverageMap => RecordedRuns::map();
$run = static fn(Plan $plan): Run => RecordedRuns::run($plan);
$ledgers = static fn(ProofStore $store, Plan $plan, Writing $writing = Writing::Auto): Ledgers => RecordedRuns::ledgers($store, $plan, $writing);

it('writes a proof of every unit that ran to the end, at the plan\'s base, and what each shard cost', function () use (
    $map,
    $run,
    $ledgers,
): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::twoShards();
    $results = RecordedRuns::ran($project, ScriptedRunner::fixture(), $map());

    $written = new Recorded(adapters: Flows::adapters($project, [], $store), settings: Flows::settings())
        ->write($plan, $results, JudgedMutants::none(), $ledgers($store, $plan), $run($plan), CannotTell::because('It failed.'), RecordedRuns::lastRun());
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

it('writes the proofs of a narrowed run, and teaches the cost model and pruning nothing from it', function (Narrowing $narrowing) use (
    $map,
    $run,
    $ledgers,
): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::twoShards();
    $results = RecordedRuns::ran($project, ScriptedRunner::fixture(), $map());

    new Recorded(adapters: Flows::adapters($project, [], $store, $narrowing), settings: Flows::settings())
        ->write($plan, $results, RecordedRuns::judged($results), $ledgers($store, $plan), $run($plan), CannotTell::because('It failed.'), RecordedRuns::lastRun());
    $ledger = LedgerRead::ledger($store->read(Scope::branch('main')));

    expect(count($ledger->proofs()))->toBe(2)
        ->and(count($ledger->timings()))->toBe(0)
        ->and($ledger->learned()->survival())->toEqual(Survival::none())
        ->and($ledger->learned()->tallies())->toEqual(MutatorTallies::none());
})->with([
    'to the security mutators' => [fn(): Narrowing => Narrowing::none()->toMutators(Mutators::named('Plus'))],
    'to one suite' => [fn(): Narrowing => Narrowing::none()->toSuite(SuiteName::of('unit'))],
]);

it('records the commit that passed, under its check, with how many of its own proofs it used', function () use (
    $map,
    $run,
    $ledgers,
): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::twoShards();
    $passed = Passed::of(Revision::ref(Flows::HEAD), 'mutation / verdict', 2);

    $results = RecordedRuns::ran($project, ScriptedRunner::fixture(), $map());

    new Recorded(adapters: Flows::adapters($project, [], $store), settings: Flows::settings())
        ->write($plan, $results, JudgedMutants::none(), $ledgers($store, $plan), $run($plan), $passed, RecordedRuns::lastRun());

    expect(LedgerRead::ledger($store->read(Scope::branch('main')))->runs()->passed())->toEqual($passed);
});

it('keeps the ledger it read, adding to it', function () use ($map, $run, $ledgers): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::twoShards();
    $earlier = Proof::of(Digest::sha256Of('earlier'), Path::of('src/Gone.php'), Mutants::none(), $run($plan));
    $store->write(Scope::branch('main'), Ledger::empty()->withProof($earlier));

    new Recorded(adapters: Flows::adapters($project, [], $store), settings: Flows::settings())->write(
        $plan,
        RecordedRuns::ran($project, ScriptedRunner::fixture(), $map()),
        JudgedMutants::none(),
        $ledgers($store, $plan),
        $run($plan),
        CannotTell::because('It failed.'),
        RecordedRuns::lastRun(),
    );

    expect(LedgerRead::ledger($store->read(Scope::branch('main')))->proofs()->has(Digest::sha256Of('earlier')))->toBeTrue();
});

it('records no proof of a unit with a flaky mutant', function () use ($map, $run, $ledgers): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::twoShards();

    new Recorded(adapters: Flows::adapters($project, [], $store), settings: Flows::settings())->write(
        $plan,
        RecordedRuns::ran($project, ScriptedRunner::fixture()->killingAgain(), $map()),
        JudgedMutants::none(),
        $ledgers($store, $plan),
        $run($plan),
        CannotTell::because('It failed.'),
        RecordedRuns::lastRun(),
    );
    $proofs = LedgerRead::ledger($store->read(Scope::branch('main')))->proofs();

    expect(count($proofs))->toBe(0);
});

it('cannot judge a shard that was handed no map, and writes nothing', function () use ($map, $run, $ledgers): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::twoShards();
    $results = RecordedRuns::ran($project, ScriptedRunner::fixture(), $map());
    unlink(sprintf('%s/.mutation-gate/coverage/shard-2/map.json.gz', $project));

    $written = new Recorded(adapters: Flows::adapters($project, [], $store), settings: Flows::settings())
        ->write($plan, $results, JudgedMutants::none(), $ledgers($store, $plan), $run($plan), CannotTell::because('It failed.'), RecordedRuns::lastRun());

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
    $results = RecordedRuns::ran($project, ScriptedRunner::fixture(), $map());

    $written = new Recorded(adapters: Flows::adapters($project, [], $store), settings: Flows::settings())
        ->write($plan, $results, JudgedMutants::none(), $read, $run($plan), CannotTell::because('It failed.'), RecordedRuns::lastRun());

    expect($written)->toEqual($read->access()->writes())
        ->and($written)->toBeInstanceOf(ReadsOnly::class)
        ->and(LedgerRead::ledger($store->read(Scope::branch('main'))))->toEqual(Ledger::empty());
})->with([
    'proofs.write is never' => [Writing::Never, fn(): RunOn => RunOn::at(Scope::branch('main'), Scope::branch('main'))],
    'a detached HEAD' => [Writing::Auto, fn(): RunOn => RunOn::detached(Scope::branch('main'))],
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

    expect(new Recorded(adapters: Flows::adapters($project, [], $store), settings: Flows::settings())->write(
        $plan,
        RecordedRuns::ran($project, ScriptedRunner::fixture(), $map()),
        JudgedMutants::none(),
        $ledgers($store, $plan),
        $run($plan),
        CannotTell::because('It failed.'),
        RecordedRuns::lastRun(),
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

    new Recorded(adapters: Flows::adapters($project, [], $store), settings: Flows::settings())->write(
        $plan,
        RecordedRuns::ranOf($plan, $project, ScriptedRunner::fixture(), $map()),
        JudgedMutants::none(),
        $ledgers($store, $plan),
        $run($plan),
        CannotTell::because('It failed.'),
        RecordedRuns::lastRun(),
    );

    expect(array_map(
        static fn(Proof $proof): string => $proof->unit()->value(),
        [...LedgerRead::ledger($store->read(Scope::branch('main')))->proofs()],
    ))->toBe(['src/Money.php']);
});

it('names with each proof the functions of its file that hold its mutants, and none where its file cannot be read', function () use (
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

            public function unmutated(): int
            {
                return 2;
            }
        }

        PHP);
    $store = new ProofStoreFake();
    $plan = Planned::twoShards();
    $killed = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', '@@ @@', 0),
        'Plus-7',
        Location::of(Path::of('src/Money.php'), Line::of(7), Line::of(7)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, '@@ @@'),
        MutantStatus::Killed,
        Seconds::of(0.1),
    )->killedBy(TestIds::of(TestId::of('MoneyTest::adds')));

    new Recorded(adapters: Flows::adapters($project, [], $store), settings: Flows::settings())->write(
        $plan,
        RecordedRuns::ranOf($plan, $project, ScriptedRunner::fixture()->answering(Mutants::of($killed), 0), $map()),
        JudgedMutants::none(),
        $ledgers($store, $plan),
        $run($plan),
        CannotTell::because('It failed.'),
        RecordedRuns::lastRun(),
    );
    $proofs = LedgerRead::ledger($store->read(Scope::branch('main')))->proofs();
    $money = $proofs->proofFor(Digest::sha256Of('money'));
    $held = $proofs->proofFor(Digest::sha256Of('held'));

    expect($money instanceof Proof ? $money->functions() : $money)
        ->toEqual(FunctionSpans::of(FunctionSpan::of('Money::add', Line::of(5), Line::of(8))))
        ->and($held instanceof Proof ? $held->functions() : $held)->toEqual(FunctionSpans::none());
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
        ->withTest(Path::of('tests/RoundTest.php'), Digest::sha256Of('round test'))
        ->takenAt(Revision::ref(str_repeat('c0', 20)));
    $plan = Planned::twoShards()
        ->digesting($digests)
        ->naming(TestNames::none()
            ->with(TestId::of('MoneyTest::adds'), TestName::in(Path::of('tests/MoneyTest.php'), 'adds'))
            ->with(TestId::of('TaxTest::rounds'), TestName::in(Path::of('tests/TaxTest.php'), 'rounds'))
            ->with(TestId::of('RoundTest::up'), TestName::in(Path::of('tests/RoundTest.php'), 'up')));
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
    $alsoKilled = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Times', '@@ @@', 0),
        'Times-3',
        Location::of(Path::of('src/Money.php'), Line::of(3), Line::of(3)),
        Mutation::of('Times', MutatorFamily::Arithmetic, '@@ @@'),
        MutantStatus::Killed,
        Seconds::of(0.1),
    )->killedBy(TestIds::of(TestId::of('RoundTest::up')));
    $results = RecordedRuns::ranOf($plan, $project, ScriptedRunner::fixture()->answering(Mutants::of($killed, $timedOut, $alsoKilled), 0), $map());

    new Recorded(adapters: Flows::adapters($project, [], $store), settings: Flows::settings())
        ->write($plan, $results, JudgedMutants::none(), $ledgers($store, $plan), $run($plan), CannotTell::because('It failed.'), RecordedRuns::lastRun());
    $ledger = LedgerRead::ledger($store->read(Scope::branch('main')));
    $money = $ledger->proofs()->proofFor(Digest::sha256Of('money'));
    $held = $ledger->proofs()->proofFor(Digest::sha256Of('held'));

    expect($money instanceof Proof ? $money->inputs() : $money)->toEqual(
        Inputs::of(Digest::sha256Of('money source'), Digest::sha256Of('mutation'))
            ->withTest(Path::of('tests/MoneyTest.php'), Digest::sha256Of('money test'))
            ->withTest(Path::of('tests/RoundTest.php'), Digest::sha256Of('round test'))
            ->takenAt(Revision::ref(str_repeat('c0', 20))),
    )
        ->and($held instanceof Proof ? $held->inputs() : $held)->toEqual(Undigested::proof());
});

it('records with each proof each test file that covers its file, where the run read a coverage map, killed one of its mutants or not', function () use (
    $map,
    $run,
    $ledgers,
): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $digests = Digests::of(Digest::sha256Of('mutation'))
        ->withSource(Path::of('src/Money.php'), Digest::sha256Of('money source'))
        ->withTest(Path::of('tests/MoneyTest.php'), Digest::sha256Of('money test'))
        ->withTest(Path::of('tests/TaxTest.php'), Digest::sha256Of('tax test'));
    $plan = Planned::twoShards()
        ->digesting($digests)
        ->naming(TestNames::none()
            ->with(TestId::of('MoneyTest::adds'), TestName::in(Path::of('tests/MoneyTest.php'), 'adds'))
            ->with(TestId::of('TaxTest::rounds'), TestName::in(Path::of('tests/TaxTest.php'), 'rounds')));
    $timedOut = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Minus', '@@ @@', 0),
        'Minus-2',
        Location::of(Path::of('src/Money.php'), Line::of(2), Line::of(2)),
        Mutation::of('Minus', MutatorFamily::Arithmetic, '@@ @@'),
        MutantStatus::TimedOut,
        Seconds::of(0.1),
    );
    $covering = CoverageMap::of()->covered(Path::of('src/Money.php'), Line::of(9), TestId::of('TaxTest::rounds'));
    $results = RecordedRuns::ranOf($plan, $project, ScriptedRunner::fixture()->answering(Mutants::of($timedOut), 0), $map());

    new Recorded(adapters: Flows::adapters($project, [], $store), settings: Flows::settings())->write(
        $plan,
        $results,
        JudgedMutants::none(),
        $ledgers($store, $plan),
        $run($plan),
        CannotTell::because('It failed.'),
        RecordedRuns::lastRun(),
        map: $covering,
    );
    $money = LedgerRead::ledger($store->read(Scope::branch('main')))->proofs()->proofFor(Digest::sha256Of('money'));

    expect($money instanceof Proof ? $money->inputs() : $money)->toEqual(
        Inputs::of(Digest::sha256Of('money source'), Digest::sha256Of('mutation'))
            ->withTest(Path::of('tests/TaxTest.php'), Digest::sha256Of('tax test')),
    );
});

it('records no digests where the plan has none', function () use ($map, $run, $ledgers): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::twoShards();

    new Recorded(adapters: Flows::adapters($project, [], $store), settings: Flows::settings())
        ->write($plan, RecordedRuns::ran($project, ScriptedRunner::fixture(), $map()), JudgedMutants::none(), $ledgers($store, $plan), $run($plan), CannotTell::because('It failed.'), RecordedRuns::lastRun());
    $money = LedgerRead::ledger($store->read(Scope::branch('main')))->proofs()->proofFor(Digest::sha256Of('money'));

    expect($money instanceof Proof ? $money->inputs() : $money)->toEqual(Undigested::proof());
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

    new Recorded(adapters: Flows::adapters($project, [], $store), settings: Flows::settings())->write(
        $plan,
        RecordedRuns::ran($project, ScriptedRunner::fixture(), $map()),
        JudgedMutants::none(),
        $ledgers($store, $plan),
        $run($plan),
        CannotTell::because('It failed.'),
        RecordedRuns::lastRun(),
    );
    $runs = LedgerRead::ledger($store->read(Scope::branch('main')))->runs();

    expect($runs->lastRun())->toEqual(RecordedRuns::lastRun())
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
        'flaky' => RecordedRuns::ranOf($plan, $project, ScriptedRunner::fixture()->killingAgain(), $map()),
        'budget' => RecordedRuns::ranWith($plan, $project, ScriptedRunner::fixture()->answering(
            Mutants::of(Judged::mutant('src/Money.php', MutantJudgement::Killed)->mutant()),
            0,
        ), $map(), Flows::settings(Budget::of('45s')), new Setup(
            Absent::setting(),
            Version::of('nightworksio/mutation-gate', '1.0.0', 'gate'),
            Digest::sha256Of('installed'),
            new TickingClock('2026-09-30T12:00:00+00:00', 10),
            new PeakMemoryFake(NotGiven::value()),
            DecidingConfig::unread(),
            GateCommand::installed(),
        )),
        default => RecordedRuns::ranMissing($plan, $project, $map()),
    };

    new Recorded(adapters: Flows::adapters($project, [], $store), settings: Flows::settings())->write(
        $plan,
        $results,
        JudgedMutants::none(),
        $ledgers($store, $plan),
        $run($plan),
        CannotTell::because('It failed.'),
        RecordedRuns::lastRun(),
    );

    expect(LedgerRead::ledger($store->read(Scope::branch('main')))->runs()->lastRun())->toBeInstanceOf(CannotTell::class);
})->with([
    'a unit flaky, so it left no proof' => ['flaky'],
    'a budget run out before a unit, every unit it ran judged whole' => ['budget'],
    'a held unit whose tests miss lines of it' => ['misses'],
]);

it('leaves no proof of a unit the run pruned mutators in, nor its time, yet counts it judged, and learns what every mutant it ran came to', function () use (
    $map,
    $run,
    $ledgers,
): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $twoShards = Planned::twoShards();
    $plan = $twoShards->considering(
        $twoShards->considered()->pruning(Pruned::of(MutatorNames::of('Plus'), Paths::of(Path::of('src/Money.php')))),
    );
    $results = RecordedRuns::ranOf($plan, $project, ScriptedRunner::fixture(), $map());

    new Recorded(adapters: Flows::adapters($project, [], $store), settings: Flows::settings())
        ->write($plan, $results, JudgedMutants::none(), $ledgers($store, $plan), $run($plan), CannotTell::because('It failed.'), RecordedRuns::lastRun());
    $ledger = LedgerRead::ledger($store->read(Scope::branch('main')));
    $learned = [];

    foreach ($ledger->learned()->survival() as $runner => $windows) {
        foreach ($windows as $mutator => $window) {
            $learned[$runner][$mutator] = $window->outcomes();
        }
    }

    expect($ledger->proofs()->has(Digest::sha256Of('money')))->toBeFalse()
        ->and($ledger->proofs()->has(Digest::sha256Of('held')))->toBeTrue()
        ->and(count($ledger->timings()))->toBe(1)
        ->and($ledger->runs()->lastRun())->toEqual(RecordedRuns::lastRun())
        ->and($learned)->toBe(['fake' => ['Decrement' => '0', 'GreaterThan' => '1', 'Minus' => '1', 'Plus' => '1']]);
});
