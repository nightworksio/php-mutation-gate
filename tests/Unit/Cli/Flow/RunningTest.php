<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\Handoff;
use NightWorksIO\MutationGate\Cli\Flow\Interruption;
use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\Setup;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Config\Budget;
use NightWorksIO\MutationGate\Config\Equivalence;
use NightWorksIO\MutationGate\Config\Flaky;
use NightWorksIO\MutationGate\Config\Runner as ConfiguredRunner;
use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Config\Tests;
use NightWorksIO\MutationGate\Config\Timeouts;
use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\TestOrder;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Hold\NotCovered;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\Kills;
use NightWorksIO\MutationGate\Core\Order\Ordering;
use NightWorksIO\MutationGate\Core\Order\Ranking;
use NightWorksIO\MutationGate\Core\Plan\Briefing;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Plan\ShardResultFile;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Unkeyed;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Processes;
use NightWorksIO\MutationGate\Core\Runner\RunnerBehaviour;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Fakes\RepositoryFake;
use NightWorksIO\MutationGate\Tests\Support\CoverageAsked;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\PeakMemoryFake;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use NightWorksIO\MutationGate\Tests\Support\StoppedClock;
use NightWorksIO\MutationGate\Tests\Support\TickingClock;

afterEach(function (): void {
    Scratch::sweep();
});

/** A setup whose clock moves on three seconds each time the run reads it. */
$ticking = static fn(): Setup => new Setup(
    Absent::setting(),
    Version::of('nightworksio/mutation-gate', '1.0.0', 'gate'),
    Digest::sha256Of('installed'),
    new TickingClock('2026-09-30T12:00:00+00:00', 3),
    new PeakMemoryFake(NotGiven::value()),
);

/** A setup whose clock moves on this many seconds each time the run reads it. */
$tickingBy = static fn(int $step): Setup => new Setup(
    Absent::setting(),
    Version::of('nightworksio/mutation-gate', '1.0.0', 'gate'),
    Digest::sha256Of('installed'),
    new TickingClock('2026-09-30T12:00:00+00:00', $step),
    new PeakMemoryFake(NotGiven::value()),
);

/** @return list<string> the paths of the units a result says its budget ran out before */
$unjudged = static fn(ShardResult|CannotJudge $result): array => $result instanceof ShardResult
    ? array_map(static fn(Unit $unit): string => $unit->path()->value(), [...$result->unjudged()])
    : [];

/** The result a shard left in a project, as the verdict reads it. */
$resultIn = static function (string $project, int $shard): ShardResult|CannotJudge {
    $file = sprintf('%s/.mutation-gate/results/%d.json', $project, $shard);

    return ShardResultFile::decode(is_file($file) ? (string) file_get_contents($file) : '');
};

/** @return list<string> each mutant's native id and status */
$statuses = static fn(ShardResult|CannotJudge $result): array => $result instanceof ShardResult
    && $result->outcome() instanceof MutationResult
    ? array_map(
        static fn(Mutant $mutant): string => sprintf('%s %s', $mutant->nativeId(), $mutant->status()->value),
        [...$result->outcome()->mutants()],
    )
    : [];

/** @return list<string> the ids flaky in a result */
$flaky = static fn(ShardResult|CannotJudge $result): array => $result instanceof ShardResult
    ? array_map(static fn(MutantId $id): string => $id->value(), [...$result->flaky()])
    : [];

/** @return list<Ordering> the order each invocation asked its tests in */
$orderings = static fn(ScriptedRunner $runner): array => array_map(
    static fn(MutationRequest $request): Ordering => $request->search()->ordering(),
    $runner->requests(),
);

it('runs the shard it is named, on the commit its plan was made on, and leaves its result', function () use (
    $ticking,
    $resultIn,
    $statuses,
): void {
    $project = Flows::project();
    $plan = Planned::handedIn($project, Planned::twoShards());
    $written = new Running(Flows::adapters($project, [], ScriptedRunner::fixture()), Flows::settings(), $ticking())
        ->run($plan, ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);

    expect($written)->toEqual(Written::to(sprintf('%s/.mutation-gate/results/1.json', $project)))
        ->and($result instanceof ShardResult ? $result->plan() : $result)->toEqual($plan->digest())
        ->and($result instanceof ShardResult ? $result->shard() : $result)->toEqual(ShardId::of(1))
        ->and($result instanceof ShardResult ? $result->units() : $result)
        ->toEqual(Keys::none()->with(Path::of('src/Money.php'), Digest::sha256Of('money')))
        ->and($statuses($result))
        ->toBe(['Plus-11 killed', 'GreaterThan-16 survived', 'Minus-21 uncovered', 'Decrement-27 timed-out'])
        ->and($result instanceof ShardResult ? $result->measured()->spent() : $result)->toEqual(Seconds::of(3.0))
        ->and($result instanceof ShardResult ? $result->measured()->runner() : $result)->toBe('fake')
        ->and($result instanceof ShardResult ? $result->measured()->at() : $result)
        ->toEqual(Moment::at('2026-09-30T12:00:03Z'))
        ->and(is_file(sprintf('%s/.mutation-gate/results/2.json', $project)))->toBeFalse();
});

it('runs the shard the environment names where none is named', function () use ($resultIn): void {
    $project = Flows::project();
    new Running(Flows::adapters($project, ['SHARD' => '2']), Flows::settings(), Flows::setup())
        ->run(Planned::handedIn($project, Planned::twoShards()), Absent::setting(), Workspace::results());

    expect($resultIn($project, 2))->toBeInstanceOf(ShardResult::class)
        ->and(is_file(sprintf('%s/.mutation-gate/results/1.json', $project)))->toBeFalse();
});

it('refuses a shard the environment names that the plan does not hold', function (): void {
    $project = Flows::project();
    $ran = new Running(Flows::adapters($project, ['SHARD' => '3']), Flows::settings(), Flows::setup())
        ->run(Planned::handedIn($project, Planned::twoShards()), Absent::setting(), Workspace::results());

    expect($ran)->toEqual(Planned::twoShards()->shard(ShardId::of(3)));
});

it('refuses a named shard the plan does not hold', function (): void {
    $project = Flows::project();

    $ran = new Running(Flows::adapters($project), Flows::settings(), Flows::setup())
        ->run(Planned::handedIn($project, Planned::twoShards()), ShardId::of(5), Workspace::results());

    expect($ran)->toEqual(CannotJudge::because(
        'The plan has no shard 5. It holds 2 shards, so this job was not planned from it.',
    ));
});

it('refuses to run a plan made on another commit', function (): void {
    $project = Flows::project();
    $elsewhere = RepositoryFake::onMain(Revision::ref('other'));

    $ran = new Running(Flows::adapters($project, [], $elsewhere), Flows::settings(), Flows::setup())
        ->run(Planned::handedIn($project, Planned::twoShards()), ShardId::of(1), Workspace::results());

    expect($ran)->toEqual(Planned::twoShards()->forCheckout(Revision::ref('other')))
        ->and(is_dir(sprintf('%s/.mutation-gate/results', $project)))->toBeFalse();
});

it('refuses to run where the commit HEAD is at cannot be read', function (): void {
    $project = Flows::project();

    $ran = new Running(Flows::adapters($project, [], Flows::lost()), Flows::settings(), Flows::setup())
        ->run(Planned::handedIn($project, Planned::twoShards()), ShardId::of(1), Workspace::results());

    expect($ran)->toEqual(CannotJudge::because(
        'The commit HEAD is at cannot be read, so the plan cannot be checked against it. git is not installed.',
    ));
});

it('runs every shard of a plan one after another, each leaving its result', function () use ($resultIn): void {
    $project = Flows::project();

    $written = new Running(Flows::adapters($project), Flows::settings(), Flows::setup())
        ->runAll(Planned::handedIn($project, Planned::twoShards()), Workspace::results());

    expect($written)->toEqual(Written::to('.mutation-gate/results'))
        ->and($resultIn($project, 1))->toBeInstanceOf(ShardResult::class)
        ->and($resultIn($project, 2))->toBeInstanceOf(ShardResult::class);
});

it('stops at the first shard whose result cannot be written', function (): void {
    $project = Flows::project();
    mkdir(sprintf('%s/.mutation-gate/results/1.json', $project), recursive: true);

    $written = new Running(Flows::adapters($project), Flows::settings(), Flows::setup())
        ->runAll(Planned::handedIn($project, Planned::twoShards()), Workspace::results());

    expect($written)->toBeInstanceOf(CannotJudge::class)
        ->and(is_file(sprintf('%s/.mutation-gate/results/2.json', $project)))->toBeFalse();
});

it('asks a runner that runs a mutant per core for every core the machine has', function (): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture()->behaving(RunnerBehaviour::standard()->runningPerCore());
    $adapters = Flows::adapters($project, [], $runner);

    new Running($adapters, Flows::settings(), Flows::setup())
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());

    expect(array_map(static fn(MutationRequest $request): Processes => $request->processes(), $runner->requests()))
        ->toEqual([$adapters->cores, $adapters->cores])
        ->and($adapters->cores)->not->toEqual(Processes::single());
});

it('runs the held path by its group, the rest by the suite, on the shard\'s map, secrets withheld', function (): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture();
    $adapters = Flows::adapters($project, [], $runner);

    new Running($adapters, Flows::settings(), Flows::setup())
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());
    [$held, $rest] = $runner->requests();

    expect(count($runner->requests()))->toBe(2)
        ->and([...$held->files()])->toEqual([Path::of('src/Held.php')])
        ->and($held->judgedBy())->toEqual(Group::named('holds:src/Held.php'))
        ->and([...$rest->files()])->toEqual([Path::of('src/Money.php')])
        ->and($rest->judgedBy())->toEqual(WholeSuite::tests())
        ->and($held->coverage())->toEqual(Handed::maps(Workspace::shardCoverage(ShardId::of(1)), Workspace::coverage()))
        ->and($rest->coverage())->toEqual(Handed::maps(Workspace::shardCoverage(ShardId::of(1)), Workspace::coverage()))
        ->and($held->processes())->toEqual(Processes::single())
        ->and($held->withheld())->toEqual(Withheld::standard()->and($adapters->withheld))
        ->and($rest->withheld())->toEqual(Withheld::standard()->and($adapters->withheld))
        ->and($runner->identified())->not->toBeEmpty()
        ->and($runner->identified())->each->toEqual($adapters->withheld);
});

it('asks for the mutants of the mutators a run is narrowed to, and of every mutator otherwise', function (): void {
    $asked = static function (Mutators $narrowedTo): array {
        $project = Flows::project();
        $scripted = ScriptedRunner::fixture();

        new Running(Flows::adapters($project, [], $scripted, $narrowedTo), Flows::settings(), Flows::setup())
            ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());

        return array_map(static fn(MutationRequest $request): Mutators => $request->mutators(), $scripted->requests());
    };
    $secured = Mutators::named('security/HashEqualsToTrue');

    expect($asked($secured))->toEqual([$secured, $secured])
        ->and($asked(Mutators::all()))->toEqual([Mutators::all(), Mutators::all()]);
});

it('caps each mutant\'s process at runner.memory, and at 1G where the config sets none', function (): void {
    $capped = static function (ConfiguredRunner ...$runner): array {
        $project = Flows::project();
        $scripted = ScriptedRunner::fixture();

        new Running(Flows::adapters($project, [], $scripted), Flows::settings(...$runner), Flows::setup())
            ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());

        return array_map(static fn(MutationRequest $request): MemoryCap => $request->memory(), $scripted->requests());
    };

    expect($capped(ConfiguredRunner::uses('fake')->cappedAt(MemoryCap::of(256, MemoryUnit::Megabytes))))
        ->toEqual([MemoryCap::of(256, MemoryUnit::Megabytes), MemoryCap::of(256, MemoryUnit::Megabytes)])
        ->and($capped())->toEqual([MemoryCap::standard(), MemoryCap::standard()]);
});

it('leaves every invocation\'s mutants in one result, with what each skipped added up', function () use (
    $resultIn,
): void {
    $project = Flows::project();
    $killed = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', '@@ @@', 0),
        'Plus-1',
        Location::of(Path::of('src/Money.php'), Line::of(1), Line::of(1)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, '@@ @@'),
        MutantStatus::Killed,
        Seconds::of(0.1),
    );
    $runner = ScriptedRunner::fixture()->answering(Mutants::of($killed), 2);

    new Running(Flows::adapters($project, [], $runner), Flows::settings(), Flows::setup())
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);
    $outcome = $result instanceof ShardResult ? $result->outcome() : $result;

    expect($outcome instanceof MutationResult ? $outcome->skipped() : $outcome)->toBe(4)
        ->and($outcome instanceof MutationResult ? count($outcome->mutants()) : $outcome)->toBe(2)
        ->and($runner->retries())->toBe([]);
});

it('runs each survivor once more and keeps those killed then as flaky', function (MutantStatus $killed) use ($resultIn, $flaky): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture()->killingAgain($killed);
    $adapters = Flows::adapters($project, [], $runner);

    new Running($adapters, Flows::settings(), Flows::setup())
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());
    $retries = $runner->retries();
    $asked = array_map(
        static fn(array $retry): array => [
            array_map(static fn(Mutant $mutant): string => $mutant->nativeId(), [...$retry[0]]),
            $retry[1],
            $retry[2],
            $retry[3],
        ],
        $retries,
    );

    $withheld = Withheld::standard()->and($adapters->withheld);

    expect($asked)->toEqual([
        [['Plus-11'], Seconds::of(10.0), Group::named('holds:src/Held.php'), $withheld],
        [['GreaterThan-16'], Seconds::of(10.0), WholeSuite::tests(), $withheld],
    ])
        ->and($flaky($resultIn($project, 1)))->toBe(array_map(
            static fn(Mutant $mutant): string => $mutant->id()->value(),
            [...$retries[0][0], ...$retries[1][0]],
        ));
})->with(['by a test' => [MutantStatus::Killed], 'by a static analyser' => [MutantStatus::KilledByStaticAnalysis]]);

it('keeps no survivor as flaky that survives again', function () use ($resultIn, $flaky): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture();

    new Running(Flows::adapters($project, [], $runner), Flows::settings(), Flows::setup())
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());

    expect(count($runner->retries()))->toBe(2)
        ->and($flaky($resultIn($project, 1)))->toBe([]);
});

it('runs no survivor proven equivalent again, unless equivalence.static is false', function (Equivalence|Flaky $setting, array $retried): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture()->checking(Checkable::inPlace(Contents::of("<?php\n\nfinal class Money\n{\n\n}\n")));

    new Running(Flows::adapters($project, [], $runner), Flows::settings($setting), Flows::setup())
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());
    $asked = array_map(
        static fn(array $retry): array => array_map(static fn(Mutant $mutant): string => $mutant->nativeId(), [...$retry[0]]),
        $runner->retries(),
    );

    expect($asked)->toBe($retried);
})->with([
    'proven equivalent' => [Equivalence::provenStatically(), [['Plus-11']]],
    'not proven' => [Equivalence::notProvenStatically(), [['Plus-11'], ['GreaterThan-16']]],
]);

it('runs no survivor again where flaky.confirmSurvivors is false', function () use ($resultIn, $flaky): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture()->killingAgain();

    $settings = Flows::settings(Flaky::notConfirmingSurvivors());

    new Running(Flows::adapters($project, [], $runner), $settings, Flows::setup())
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());

    expect($runner->retries())->toBe([])
        ->and($flaky($resultIn($project, 1)))->toBe([]);
});

it('runs survivors again within what the budget has left, not what the invocation started with', function () use ($tickingBy): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture();
    $unbudgeted = ScriptedRunner::fixture();

    new Running(Flows::adapters($project, [], $runner), Flows::settings(Budget::of('1000s')), $tickingBy(10))
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());
    $other = Flows::project();
    new Running(Flows::adapters($other, [], $unbudgeted), Flows::settings(), $tickingBy(10))
        ->run(Planned::handedIn($other, Planned::oneShard()), ShardId::of(1), Workspace::results());
    $seconds = static fn(Seconds|Unlimited $deadline): float => $deadline instanceof Seconds ? $deadline->seconds() : -1.0;
    $first = $seconds($runner->requests()[0]->deadline());
    $again = array_map(static fn(array $retry): float => $seconds($retry[4]->deadline()), $runner->retries());

    expect($again)->toHaveCount(2)
        ->and($again[0])->toBeLessThan($first)->toBeGreaterThan(0.0)
        ->and($again[1])->toBeLessThan($again[0])
        ->and(array_map(static fn(array $retry): Seconds|Unlimited => $retry[4]->deadline(), $unbudgeted->retries()))
        ->toEqual([Unlimited::time(), Unlimited::time()]);
});

it('runs survivors again with the timeout the config sets', function (): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture();

    $settings = Flows::settings(Timeouts::seconds(25));

    new Running(Flows::adapters($project, [], $runner), $settings, Flows::setup())
        ->run(Planned::handedIn($project, Planned::twoShards()), ShardId::of(1), Workspace::results());

    expect($runner->retries()[0][1])->toEqual(Seconds::of(25.0));
});

it('leaves the runner\'s cannot judge as the shard\'s result, with nothing flaky', function (
    string $how,
) use ($resultIn, $flaky): void {
    $project = Flows::project();
    $runner = $how === 'mutate'
        ? ScriptedRunner::fixture()->refusing('The suite failed without mutants.')
        : ScriptedRunner::fixture()->refusingAgain('The suite failed without mutants.');

    $written = new Running(Flows::adapters($project, [], $runner), Flows::settings(), Flows::setup())
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);

    expect($written)->toEqual(Written::to(sprintf('%s/.mutation-gate/results/1.json', $project)))
        ->and($result instanceof ShardResult ? $result->outcome() : $result)
        ->toEqual(CannotJudge::because('The suite failed without mutants.'))
        ->and($flaky($result))->toBe([])
        ->and(count($runner->requests()))->toBe(1);
})->with(['mutate', 'retry']);

it('names no runner in what it measured where the runner cannot name itself', function () use ($resultIn): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture()->unnamed('No runner is installed.');

    new Running(Flows::adapters($project, [], $runner), Flows::settings(), Flows::setup())
        ->run(Planned::handedIn($project, Planned::twoShards()), ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);

    expect($result instanceof ShardResult ? $result->measured()->runner() : $result)->toBe('');
});

it('leaves a unit the plan did not key as one with no key', function () use ($resultIn): void {
    $project = Flows::project();
    $root = Package::at(Path::root());
    $money = Shard::of(ShardId::of(1), $root, Units::of(Planned::money()), Seconds::of(2.0), 'money');
    $plan = Plan::of(Revision::ref(Flows::HEAD), Digest::sha256Of('b'), Keys::none(), Shards::of($money));

    new Running(Flows::adapters($project), Flows::settings(), Flows::setup())
        ->run($plan, ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);

    expect($result instanceof ShardResult ? $result->units() : $result)->toEqual(Keys::none()->with(
        Path::of('src/Money.php'),
        Unkeyed::because('src/Money.php was not considered, so it has no key.'),
    ));
});

it('names no flaky mutant it did not run again', function () use ($resultIn): void {
    $project = Flows::project();

    new Running(Flows::adapters($project), Flows::settings(), Flows::setup())
        ->run(Planned::handedIn($project, Planned::twoShards()), ShardId::of(2), Workspace::results());
    $result = $resultIn($project, 2);

    expect($result instanceof ShardResult ? $result->flaky() : $result)->toEqual(MutantIds::none());
});

it('keeps with each timed-out mutant the time its covering tests take, from the map it was handed', function () use (
    $resultIn,
): void {
    $project = Flows::project();
    new Handoff(Directory::at($project))->write(Planned::oneShard(), Flows::map(), KillHistory::none());

    new Running(Flows::adapters($project, [], ScriptedRunner::fixture()), Flows::settings(), Flows::setup())
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);
    $outcome = $result instanceof ShardResult ? $result->outcome() : $result;
    $times = array_map(
        static fn(Mutant $mutant): string => sprintf('%s %s', $mutant->nativeId(), $mutant->unmutatedNeed()::class),
        array_values(array_filter(
            $outcome instanceof MutationResult ? [...$outcome->mutants()] : [],
            static fn(Mutant $mutant): bool => $mutant->status() === MutantStatus::TimedOut,
        )),
    );
    $timed = array_values(array_filter(
        $outcome instanceof MutationResult ? [...$outcome->mutants()] : [],
        static fn(Mutant $mutant): bool => $mutant->unmutatedNeed() instanceof Seconds,
    ));

    expect($times)->toBe([sprintf('Decrement-27 %s', Seconds::class)])
        ->and($timed[0]->unmutatedNeed())->toEqual(Seconds::of(0.2));
});

it('runs each timeout its cap decided once more with the cap doubled, up to timeouts.retries', function (
    Setting $retries,
    RunnerBehaviour $behaviour,
    array $retried,
) use ($statuses, $resultIn): void {
    $project = Flows::project();
    $scripted = ScriptedRunner::fixture()->behaving($behaviour);
    $adapters = Flows::adapters($project, [], $scripted);

    new Running(
        $adapters,
        Flows::settings(Timeouts::seconds(5), $retries, Flaky::notConfirmingSurvivors()),
        Flows::setup(),
    )->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());

    expect(array_map(
        static fn(array $retry): array => [
            array_map(static fn(Mutant $mutant): string => $mutant->nativeId(), [...$retry[0]]),
            $retry[1],
        ],
        $scripted->retries(),
    ))->toEqual($retried)
        ->and(array_map(static fn(array $retry): Withheld => $retry[3], $scripted->retries()))
        ->each->toEqual(Withheld::standard()->and($adapters->withheld))
        ->and($statuses($resultIn($project, 1)))
        ->toContain($retried === [] ? 'Decrement-27 timed-out' : 'Decrement-27 survived');
})->with([
    'a runner whose cap can be raised' => [
        Timeouts::retries(20),
        RunnerBehaviour::standard(),
        [[['Decrement-27'], Seconds::of(10.0)]],
    ],
    'no retries left' => [Timeouts::retries(0), RunnerBehaviour::standard(), []],
    'a runner whose limit cannot be raised' => [Timeouts::retries(20), RunnerBehaviour::standard()->raisingNoLimit(), []],
]);

it('runs no timeout again whose limit its runner\'s own formula decided', function (): void {
    $project = Flows::project();
    $scripted = ScriptedRunner::fixture();

    $settings = Flows::settings(Flaky::notConfirmingSurvivors());

    new Running(Flows::adapters($project, [], $scripted), $settings, Flows::setup())
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());

    expect($scripted->retries())->toBe([]);
});

it('mutates no held unit whose holding tests miss lines of it, and leaves why', function () use ($resultIn): void {
    $project = Flows::project();
    new Handoff(Directory::at($project))->write(Planned::oneShard(), Flows::map(), KillHistory::none());
    $scripted = ScriptedRunner::fixture();
    $runner = new CoverageAsked($scripted, CoverageMap::empty());
    $adapters = Flows::adapters($project, [], $runner);

    new Running($adapters, Flows::settings(), Flows::setup())
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);

    expect($runner->asked())->toEqual([
        CoverageRun::of(Group::named('holds:src/Held.php'), Path::of('.mutation-gate/held/shard-1'))
            ->withholding($adapters->withheld),
    ])
        ->and($result instanceof ShardResult ? [...$result->misses()] : $result)->toEqual([NotCovered::because(
            Planned::held(),
            <<<'SAID'
                holds:src/Held.php does not cover src/Held.php, so its mutants cannot be judged by it.
                Not reached: src/Held.php, all of it
                Add the test that runs them to the group.
                SAID,
        )])
        ->and(array_map(
            static fn(MutationRequest $request): string => $request->judgedBy()::class,
            $scripted->requests(),
        ))->toBe([WholeSuite::class]);
});

it('mutates a unit the whole suite judges, though the map reaches none of it, and runs no holding tests for it', function () use (
    $resultIn,
): void {
    $project = Flows::project();
    new Handoff(Directory::at($project))->write(Planned::oneShard(), CoverageMap::empty(), KillHistory::none());
    $scripted = ScriptedRunner::fixture();
    $runner = new CoverageAsked($scripted, Flows::map());
    $adapters = Flows::adapters($project, [], $runner);

    new Running($adapters, Flows::settings(), Flows::setup())
        ->run(Planned::oneShard(), ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);

    expect($runner->asked())->toEqual([
        CoverageRun::of(Group::named('holds:src/Held.php'), Path::of('.mutation-gate/held/shard-1'))
            ->withholding($adapters->withheld),
    ])
        ->and($result instanceof ShardResult ? [...$result->misses()] : $result)->toBe([])
        ->and(array_map(static fn(MutationRequest $request): array => [...$request->files()], $scripted->requests()))
        ->toEqual([[Path::of('src/Held.php')], [Path::of('src/Money.php')]]);
});

it('mutates each held unit its holding tests cover, and cannot judge a shard whose held tests fail alone', function (
    CoverageMap|CannotJudge $answer,
    string $outcome,
) use ($resultIn): void {
    $project = Flows::project();
    new Handoff(Directory::at($project))->write(Planned::oneShard(), Flows::map(), KillHistory::none());

    new Running(
        Flows::adapters($project, [], new CoverageAsked(ScriptedRunner::fixture(), $answer)),
        Flows::settings(),
        Flows::setup(),
    )->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);
    $said = $result instanceof ShardResult ? $result->outcome() : $result;

    expect($said instanceof CannotJudge ? $said->why() : $said::class)->toBe($outcome)
        ->and($result instanceof ShardResult ? count($result->misses()) : $result)->toBe(0);
})->with([
    'tests that cover what they hold' => [Flows::map(), MutationResult::class],
    'tests that fail on their own' => [
        CannotJudge::because('The group failed.'),
        sprintf(
            '%s %s',
            'The tests that hold src/Held.php cannot run on their own under coverage, so they cannot judge it.',
            'The group failed.',
        ),
    ],
]);

it('cannot judge a shard handed no map, and mutates nothing of it, held units and all', function () use (
    $resultIn,
): void {
    $project = Flows::project();
    $runner = new CoverageAsked(ScriptedRunner::fixture(), Flows::map());
    $plain = ScriptedRunner::fixture();

    new Running(Flows::adapters($project, [], $runner), Flows::settings(), Flows::setup())
        ->run(Planned::oneShard(), ShardId::of(1), Workspace::results());
    new Running(Flows::adapters($project, [], $plain), Flows::settings(), Flows::setup())
        ->run(Planned::oneShard(), ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);

    expect($runner->asked())->toBe([])
        ->and($plain->requests())->toBe([])
        ->and($result instanceof ShardResult ? $result->outcome() : $result)
        ->toEqual(new Handoff(Directory::at($project))->read(ShardId::of(1)))
        ->and($result instanceof ShardResult ? $result->outcome() : $result)->toBeInstanceOf(CannotJudge::class);
});

it('weighs each mutant out of memory by the peak its plan measured, and leaves an older plan\'s unknown', function (
    MemoryCap|NotGiven $peak,
    MemoryCap|Unmeasured $weighed,
) use ($resultIn): void {
    $project = Flows::project();
    $outOfMemory = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', '@@ @@', 0),
        'Plus-1',
        Location::of(Path::of('src/Money.php'), Line::of(1), Line::of(1)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, '@@ @@'),
        MutantStatus::OutOfMemory,
        Seconds::of(0.5),
    )->withLimit(MemoryCap::of(64, MemoryUnit::Megabytes));
    $scripted = ScriptedRunner::fixture()->answering(Mutants::of($outOfMemory), 0);

    new Running(Flows::adapters($project, [], $scripted), Flows::settings(), Flows::setup())
        ->run(
            Planned::handedIn($project, Planned::oneShard()->briefed(Briefing::standard()->weighing($peak))),
            ShardId::of(1),
            Workspace::results(),
        );
    $result = $resultIn($project, 1);
    $outcome = $result instanceof ShardResult ? $result->outcome() : $result;
    $mutants = $outcome instanceof MutationResult ? [...$outcome->mutants()] : [];

    expect($mutants[0]->unmutatedNeed())->toEqual($weighed)
        ->and($mutants[0]->limit())->toEqual(MemoryCap::of(64, MemoryUnit::Megabytes));
})->with([
    'measured' => [MemoryCap::of(20, MemoryUnit::Megabytes), MemoryCap::of(20, MemoryUnit::Megabytes)],
    'an older plan' => [NotGiven::value(), Unmeasured::duration()],
]);

it('spends one timeouts.retries across every invocation of a shard', function (): void {
    $project = Flows::project();
    $timedOut = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', '@@ @@', 0),
        'Plus-1',
        Location::of(Path::of('src/Money.php'), Line::of(1), Line::of(1)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, '@@ @@'),
        MutantStatus::TimedOut,
        Seconds::of(5.0),
    )->withLimit(Seconds::of(5.0));
    $scripted = ScriptedRunner::fixture()->answering(Mutants::of($timedOut), 0);

    new Running(
        Flows::adapters($project, [], $scripted),
        Flows::settings(Timeouts::seconds(5), Timeouts::retries(1), Flaky::notConfirmingSurvivors()),
        Flows::setup(),
    )->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());

    expect($scripted->requests())->toHaveCount(2)
        ->and($scripted->retries())->toHaveCount(1);
});

it('runs each mutant\'s likely killers first, by the kill history the plan handed the shard', function () use (
    $orderings,
): void {
    $project = Flows::project();
    $ranked = Ranking::of(Kills::of(TestId::of('MoneyTest::adds'), 2));
    $history = KillHistory::none()->withFunction(Enclosing::named(Path::of('src/Money.php'), 'add'), $ranked);
    new Handoff(Directory::at($project))->write(Planned::oneShard(), Flows::map(), $history);
    $runner = ScriptedRunner::fixture();

    new Running(Flows::adapters($project, [], $runner), Flows::settings(), Flows::setup())
        ->run(Planned::oneShard(), ShardId::of(1), Workspace::results());
    $handed = $history->onlyIn(Paths::of(Path::of('src/Money.php')));

    $ordered = Ordering::of(TestOrder::KillersFirst, $handed);

    expect($orderings($runner))->toEqual([$ordered, $ordered])
        ->and($handed)->not->toEqual(KillHistory::none());
});

it('asks the runner for every killer of each mutant where the plan records a full kill matrix, and the first otherwise', function (): void {
    $project = Flows::project();
    $full = ScriptedRunner::fixture();
    $first = ScriptedRunner::fixture();

    new Running(Flows::adapters($project, [], $full), Flows::settings(), Flows::setup())->run(
        Planned::handedIn($project, Planned::oneShard()->briefed(Briefing::standard()->recording(MatrixKind::Full))),
        ShardId::of(1),
        Workspace::results(),
    );
    new Running(Flows::adapters($project, [], $first), Flows::settings(), Flows::setup())
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());
    $matrices = static fn(ScriptedRunner $runner): array => array_map(
        static fn(MutationRequest $request): MatrixKind => $request->search()->matrix(),
        $runner->requests(),
    );

    expect($matrices($full))->toBe([MatrixKind::Full, MatrixKind::Full])
        ->and($matrices($first))->toBe([MatrixKind::FirstKiller, MatrixKind::FirstKiller]);
});

it('runs the tests in the runner\'s own order where tests.order says so', function () use ($orderings): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture();

    new Running(Flows::adapters($project, [], $runner), Flows::settings(Tests::inRunnerOrder()), Flows::setup())
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());

    $own = Ordering::of(TestOrder::Runner, KillHistory::none());

    expect($orderings($runner))->toEqual([$own, $own]);
});

it('orders a shard handed no kill history as though nothing was killed yet, and warns of nothing', function () use (
    $resultIn,
    $orderings,
): void {
    $project = Flows::project();
    $plan = Planned::handedIn($project, Planned::oneShard());
    unlink(sprintf('%s/.mutation-gate/coverage/shard-1/killers.json', $project));
    $runner = ScriptedRunner::fixture();

    new Running(Flows::adapters($project, [], $runner), Flows::settings(), Flows::setup())
        ->run($plan, ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);

    $cold = Ordering::of(TestOrder::KillersFirst, KillHistory::none());

    expect($orderings($runner))->toEqual([$cold, $cold])
        ->and($result instanceof ShardResult ? $result->warnings() : $result)->toEqual(Warnings::none())
        ->and($result instanceof ShardResult ? $result->outcome() : $result)->toBeInstanceOf(MutationResult::class);
});

it('judges a shard whose kill history cannot be read without it, and warns of it', function () use (
    $resultIn,
    $statuses,
    $orderings,
): void {
    $project = Flows::project();
    $plan = Planned::handedIn($project, Planned::oneShard());
    Scratch::write($project, '.mutation-gate/coverage/shard-1/killers.json', '{"format": 1, "tests": 3}');
    $runner = ScriptedRunner::fixture();

    new Running(Flows::adapters($project, [], $runner), Flows::settings(), Flows::setup())
        ->run($plan, ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);
    $warnings = $result instanceof ShardResult ? [...$result->warnings()] : [];

    $cold = Ordering::of(TestOrder::KillersFirst, KillHistory::none());

    expect($orderings($runner))->toEqual([$cold, $cold])
        ->and($statuses($result))->not->toBeEmpty()
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0] ?? null)->toBeInstanceOf(Warning::class)
        ->and(($warnings[0] ?? null)?->text())
        ->toStartWith('Shard 1 ran its tests without the kill history the plan handed it. A kill history cannot be read: ');
});

it('runs every batch that fits a budget with the time left, leaving nothing unjudged', function () use ($resultIn, $unjudged): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture();
    $setup = new Setup(
        Absent::setting(),
        Version::of('nightworksio/mutation-gate', '1.0.0', 'gate'),
        Digest::sha256Of('installed'),
        new StoppedClock('2026-09-30T12:00:00Z'),
        new PeakMemoryFake(NotGiven::value()),
    );

    new Running(Flows::adapters($project, [], $runner), Flows::settings(Budget::of('2s')), $setup)
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());

    expect(array_map(
        static fn(MutationRequest $request): array => [
            array_map(static fn(Path $file): string => $file->value(), [...$request->files()]),
            $request->deadline(),
        ],
        $runner->requests(),
    ))->toEqual([[['src/Money.php'], Seconds::of(2.0)], [['src/Held.php'], Seconds::of(2.0)]])
        ->and($unjudged($resultIn($project, 1)))->toBe([]);
});

it('starts nothing a budget has no room for, and leaves every unit unjudged', function () use ($resultIn, $unjudged, $statuses): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture();
    $setup = new Setup(
        Absent::setting(),
        Version::of('nightworksio/mutation-gate', '1.0.0', 'gate'),
        Digest::sha256Of('installed'),
        new StoppedClock('2026-09-30T12:00:00Z'),
        new PeakMemoryFake(NotGiven::value()),
    );

    new Running(Flows::adapters($project, [], $runner), Flows::settings(Budget::of('1s')), $setup)
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);

    expect($runner->requests())->toBe([])
        ->and($unjudged($result))->toBe(['src/Money.php', 'src/Held.php'])
        ->and($statuses($result))->toBe([]);
});

it('stops before the batch after the one an interruption arrived in, leaving the units no batch took unjudged', function () use (
    $resultIn,
    $unjudged,
): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture();
    $setup = new Setup(
        Absent::setting(),
        Version::of('nightworksio/mutation-gate', '1.0.0', 'gate'),
        Digest::sha256Of('installed'),
        new StoppedClock('2026-09-30T12:00:00Z'),
        new PeakMemoryFake(NotGiven::value()),
    );
    $looks = 0;
    $arrived = Interruption::when(static function () use (&$looks): bool {
        ++$looks;

        return $looks > 1;
    });

    new Running(Flows::adapters($project, [], $runner), Flows::settings(Budget::of('2s')), $setup)
        ->interrupted($arrived)
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());

    expect(array_map(
        static fn(MutationRequest $request): array => array_map(static fn(Path $file): string => $file->value(), [...$request->files()]),
        $runner->requests(),
    ))->toEqual([['src/Money.php']])
        ->and($unjudged($resultIn($project, 1)))->toBe(['src/Held.php']);
});

it('leaves the units no batch took unjudged, and each survivor it had no time to confirm', function () use (
    $tickingBy,
    $resultIn,
    $unjudged,
): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture();

    new Running(Flows::adapters($project, [], $runner), Flows::settings(Budget::of('25s')), $tickingBy(10))
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);
    $outcome = $result instanceof ShardResult ? $result->outcome() : $result;
    $survivor = array_values(array_filter(
        $outcome instanceof MutationResult ? [...$outcome->mutants()] : [],
        static fn(Mutant $mutant): bool => $mutant->nativeId() === 'GreaterThan-16',
    ));

    expect(count($runner->requests()))->toBe(1)
        ->and($runner->requests()[0]->deadline())->toEqual(Seconds::of(5.0))
        ->and($runner->retries())->toBe([])
        ->and($unjudged($result))->toBe(['src/Held.php'])
        ->and($survivor[0]->status())->toBe(MutantStatus::Unjudged)
        ->and($survivor[0]->reason())->toEqual(OutOfTime::BeforeConfirming->reason());
});

it('leaves a batch unjudged whose runner stopped at the deadline, and cannot judge one that failed before it', function (
    int $step,
    array $left,
    bool $judged,
) use ($tickingBy, $resultIn, $unjudged): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture()->refusing('Pest was stopped at its deadline before it had made its mutants.');

    new Running(Flows::adapters($project, [], $runner), Flows::settings(Budget::of('25s')), $tickingBy($step))
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);

    expect($unjudged($result))->toBe($left)
        ->and($result instanceof ShardResult && $result->outcome() instanceof MutationResult)->toBe($judged);
})->with([
    'stopped at the deadline' => [10, ['src/Money.php', 'src/Held.php'], true],
    'failed before it' => [1, [], false],
]);

it('runs no timeout again whose doubled cap does not fit the time left, and leaves it unjudged', function () use (
    $tickingBy,
    $statuses,
    $resultIn,
): void {
    $project = Flows::project();
    $timedOut = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', '@@ @@', 0),
        'Plus-1',
        Location::of(Path::of('src/Money.php'), Line::of(1), Line::of(1)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, '@@ @@'),
        MutantStatus::TimedOut,
        Seconds::of(5.0),
    )->withLimit(Seconds::of(5.0));
    $scripted = ScriptedRunner::fixture()->answering(Mutants::of($timedOut), 0);

    new Running(
        Flows::adapters($project, [], $scripted),
        Flows::settings(Timeouts::seconds(5), Timeouts::retries(2), Flaky::notConfirmingSurvivors(), Budget::of('6s')),
        $tickingBy(1),
    )->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());

    expect($scripted->retries())->toBe([])
        ->and($statuses($resultIn($project, 1)))->toContain('Plus-1 unjudged');
});
