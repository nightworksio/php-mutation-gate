<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\Handoff;
use NightWorksIO\MutationGate\Cli\Flow\Ledgers;
use NightWorksIO\MutationGate\Cli\Flow\Recorded;
use NightWorksIO\MutationGate\Cli\Flow\Results;
use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\Standing;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Bases;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Measurement;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\ReadsOnly;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Proof\Unkeyed;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\CostModel;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Tests\Fakes\CostModelFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

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
    new Handoff(Directory::at($project))->write($plan, $map);
    new Running(Flows::adapters($project, [], $runner), Flows::settings(), Flows::setup())
        ->runAll($plan, Workspace::results());
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

    $written = new Recorded(Flows::adapters($project, [], $store))
        ->write($plan, $results, $ledgers($store, $plan), $run($plan), CannotTell::because('It failed.'));
    $ledger = $store->read(Scope::branch('main'));
    $proof = $ledger->proofs()->proofFor(Digest::sha256Of('money'));

    expect($written)->toEqual(Written::to('memory:refs/heads/main'))
        ->and($ledger->bases())->toEqual(Bases::of($plan->base()))
        ->and(count($ledger->proofs()))->toBe(2)
        ->and($proof instanceof Proof ? [$proof->unit(), $proof->run(), count($proof->reported())] : $proof)
        ->toEqual([Path::of('src/Money.php'), $run($plan), 4])
        ->and($ledger->proofs()->has(Digest::sha256Of('held')))->toBeTrue()
        ->and(count($ledger->timings()))->toBe(2)
        ->and($ledger->lastPassed())->toBeInstanceOf(CannotTell::class);
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

    new Recorded(Flows::adapters($project, [], $store))
        ->write($plan, $results, $ledgers($store, $plan), $run($plan), $passed);

    expect($store->read(Scope::branch('main'))->lastPassed())->toEqual($passed);
});

it('keeps the ledger it read, adding to it', function () use ($map, $run, $ledgers): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::twoShards();
    $earlier = Proof::of(Digest::sha256Of('earlier'), Path::of('src/Gone.php'), Mutants::none(), $run($plan));
    $store->write(Scope::branch('main'), Ledger::empty()->withProof($earlier));

    new Recorded(Flows::adapters($project, [], $store))->write(
        $plan,
        recordedRan($project, ScriptedRunner::fixture(), $map()),
        $ledgers($store, $plan),
        $run($plan),
        CannotTell::because('It failed.'),
    );

    expect($store->read(Scope::branch('main'))->proofs()->has(Digest::sha256Of('earlier')))->toBeTrue();
});

it('records no proof of a unit with a flaky mutant', function () use ($map, $run, $ledgers): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::twoShards();

    new Recorded(Flows::adapters($project, [], $store))->write(
        $plan,
        recordedRan($project, ScriptedRunner::fixture()->killingAgain(), $map()),
        $ledgers($store, $plan),
        $run($plan),
        CannotTell::because('It failed.'),
    );
    $proofs = $store->read(Scope::branch('main'))->proofs();

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

        public function cost(Unit $unit, Timings $learned): Seconds
        {
            return Seconds::of(1.0);
        }

        public function learn(Units $units, Mutants $mutants, CoverageMap $coverage, Measurement $measured): Timings
        {
            $this->learned[] = [$units, count($mutants), $coverage];

            return new CostModelFake(Seconds::of(1.0))->learn($units, $mutants, $coverage, $measured);
        }
    };

    new Recorded(Flows::adapters($project, [], $store, $costs))->write(
        $plan,
        recordedRan($project, ScriptedRunner::fixture(), $map()),
        $ledgers($store, $plan),
        $run($plan),
        CannotTell::because('It failed.'),
    );

    expect($costs->learned())->toEqual([
        [Units::of(Planned::money()), 4, $map()->onlyFor(Paths::of(Path::of('src/Money.php')))],
        [Units::of(Planned::held()), 1, $map()->onlyFor(Paths::none())],
    ]);
});

it('cannot judge a shard that was handed no map, and writes nothing', function () use ($map, $run, $ledgers): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::twoShards();
    $results = recordedRan($project, ScriptedRunner::fixture(), $map());
    unlink(sprintf('%s/.mutation-gate/coverage/shard-2/map.json.gz', $project));

    $written = new Recorded(Flows::adapters($project, [], $store))
        ->write($plan, $results, $ledgers($store, $plan), $run($plan), CannotTell::because('It failed.'));

    expect($written)->toEqual(new Handoff(Directory::at($project))->read(ShardId::of(2)))
        ->and($written)->toBeInstanceOf(CannotJudge::class)
        ->and($store->read(Scope::branch('main')))->toEqual(Ledger::empty());
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

    $written = new Recorded(Flows::adapters($project, [], $store))
        ->write($plan, $results, $read, $run($plan), CannotTell::because('It failed.'));

    expect($written)->toEqual($read->access()->writes())
        ->and($written)->toBeInstanceOf(ReadsOnly::class)
        ->and($store->read(Scope::branch('main')))->toEqual(Ledger::empty());
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
    };
    $plan = Planned::twoShards();

    expect(new Recorded(Flows::adapters($project, [], $store))->write(
        $plan,
        recordedRan($project, ScriptedRunner::fixture(), $map()),
        $ledgers($store, $plan),
        $run($plan),
        CannotTell::because('It failed.'),
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

    new Recorded(Flows::adapters($project, [], $store))->write(
        $plan,
        recordedRanOf($plan, $project, ScriptedRunner::fixture(), $map()),
        $ledgers($store, $plan),
        $run($plan),
        CannotTell::because('It failed.'),
    );

    expect(array_map(
        static fn(Proof $proof): string => $proof->unit()->value(),
        [...$store->read(Scope::branch('main'))->proofs()],
    ))->toBe(['src/Money.php']);
});
