<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\Setup;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Config\Flaky;
use NightWorksIO\MutationGate\Config\Timeouts;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Plan\ShardResultFile;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Unkeyed;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\RepositoryFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
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
);

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

it('runs the shard it is named, on the commit its plan was made on, and leaves its result', function () use (
    $ticking,
    $resultIn,
    $statuses,
): void {
    $project = Flows::project();
    $plan = Planned::twoShards();
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

it('runs the shard the CI names where none is named', function () use ($resultIn): void {
    $project = Flows::project();
    $ci = new CiPlanFake(ShardId::of(2), RunOn::at(Scope::branch('main'), Scope::branch('main')));

    new Running(Flows::adapters($project, [], $ci), Flows::settings(), Flows::setup())
        ->run(Planned::twoShards(), Absent::setting(), Workspace::results());

    expect($resultIn($project, 2))->toBeInstanceOf(ShardResult::class)
        ->and(is_file(sprintf('%s/.mutation-gate/results/1.json', $project)))->toBeFalse();
});

it('refuses a shard the CI names that the plan does not hold', function (): void {
    $project = Flows::project();
    $ci = new CiPlanFake(ShardId::of(3), RunOn::at(Scope::branch('main'), Scope::branch('main')));

    $ran = new Running(Flows::adapters($project, [], $ci), Flows::settings(), Flows::setup())
        ->run(Planned::twoShards(), Absent::setting(), Workspace::results());

    expect($ran)->toEqual(Planned::twoShards()->shard(ShardId::of(3)));
});

it('refuses a named shard the plan does not hold', function (): void {
    $project = Flows::project();

    $ran = new Running(Flows::adapters($project), Flows::settings(), Flows::setup())
        ->run(Planned::twoShards(), ShardId::of(5), Workspace::results());

    expect($ran)->toEqual(CannotJudge::because(
        'The plan has no shard 5. It holds 2 shards, so this job was not planned from it.',
    ));
});

it('refuses to run a plan made on another commit', function (): void {
    $project = Flows::project();
    $elsewhere = RepositoryFake::onMain(Revision::ref('other'));

    $ran = new Running(Flows::adapters($project, [], $elsewhere), Flows::settings(), Flows::setup())
        ->run(Planned::twoShards(), ShardId::of(1), Workspace::results());

    expect($ran)->toEqual(Planned::twoShards()->forCheckout(Revision::ref('other')))
        ->and(is_dir(sprintf('%s/.mutation-gate/results', $project)))->toBeFalse();
});

it('refuses to run where the commit HEAD is at cannot be read', function (): void {
    $project = Flows::project();

    $ran = new Running(Flows::adapters($project, [], Flows::lost()), Flows::settings(), Flows::setup())
        ->run(Planned::twoShards(), ShardId::of(1), Workspace::results());

    expect($ran)->toEqual(CannotJudge::because(
        'The commit HEAD is at cannot be read, so the plan cannot be checked against it. git is not installed.',
    ));
});

it('runs every shard of a plan one after another, each leaving its result', function () use ($resultIn): void {
    $project = Flows::project();

    $written = new Running(Flows::adapters($project), Flows::settings(), Flows::setup())
        ->runAll(Planned::twoShards(), Workspace::results());

    expect($written)->toEqual(Written::to('.mutation-gate/results'))
        ->and($resultIn($project, 1))->toBeInstanceOf(ShardResult::class)
        ->and($resultIn($project, 2))->toBeInstanceOf(ShardResult::class);
});

it('stops at the first shard whose result cannot be written', function (): void {
    $project = Flows::project();
    mkdir(sprintf('%s/.mutation-gate/results/1.json', $project), recursive: true);

    $written = new Running(Flows::adapters($project), Flows::settings(), Flows::setup())
        ->runAll(Planned::twoShards(), Workspace::results());

    expect($written)->toBeInstanceOf(CannotJudge::class)
        ->and(is_file(sprintf('%s/.mutation-gate/results/2.json', $project)))->toBeFalse();
});

it('runs the held path by its group, the rest by the suite, on the shard\'s map, secrets withheld', function (): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture();
    $adapters = Flows::adapters($project, [], $runner);

    new Running($adapters, Flows::settings(), Flows::setup())
        ->run(Planned::oneShard(), ShardId::of(1), Workspace::results());
    [$held, $rest] = $runner->requests();

    expect(count($runner->requests()))->toBe(2)
        ->and([...$held->files()])->toEqual([Path::of('src/Held.php')])
        ->and($held->judgedBy())->toEqual(Group::named('holds:src/Held.php'))
        ->and([...$rest->files()])->toEqual([Path::of('src/Money.php')])
        ->and($rest->judgedBy())->toEqual(WholeSuite::tests())
        ->and($held->coverage())->toEqual(Workspace::shardCoverage(ShardId::of(1)))
        ->and($rest->coverage())->toEqual(Workspace::shardCoverage(ShardId::of(1)))
        ->and($held->withheld())->toEqual(Withheld::standard()->and($adapters->withheld))
        ->and($rest->withheld())->toEqual(Withheld::standard()->and($adapters->withheld));
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
        ->run(Planned::oneShard(), ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);
    $outcome = $result instanceof ShardResult ? $result->outcome() : $result;

    expect($outcome instanceof MutationResult ? $outcome->skipped() : $outcome)->toBe(4)
        ->and($outcome instanceof MutationResult ? count($outcome->mutants()) : $outcome)->toBe(2)
        ->and($runner->retries())->toBe([]);
});

it('runs each survivor once more and keeps those killed then as flaky', function () use ($resultIn, $flaky): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture()->killingAgain();
    $adapters = Flows::adapters($project, [], $runner);

    new Running($adapters, Flows::settings(), Flows::setup())
        ->run(Planned::oneShard(), ShardId::of(1), Workspace::results());
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
});

it('keeps no survivor as flaky that survives again', function () use ($resultIn, $flaky): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture();

    new Running(Flows::adapters($project, [], $runner), Flows::settings(), Flows::setup())
        ->run(Planned::oneShard(), ShardId::of(1), Workspace::results());

    expect(count($runner->retries()))->toBe(2)
        ->and($flaky($resultIn($project, 1)))->toBe([]);
});

it('runs no survivor again where flaky.confirmSurvivors is false', function () use ($resultIn, $flaky): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture()->killingAgain();

    $settings = Flows::settings(Flaky::notConfirmingSurvivors());

    new Running(Flows::adapters($project, [], $runner), $settings, Flows::setup())
        ->run(Planned::oneShard(), ShardId::of(1), Workspace::results());

    expect($runner->retries())->toBe([])
        ->and($flaky($resultIn($project, 1)))->toBe([]);
});

it('runs survivors again with the timeout the config sets', function (): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture();

    $settings = Flows::settings(Timeouts::seconds(25));

    new Running(Flows::adapters($project, [], $runner), $settings, Flows::setup())
        ->run(Planned::twoShards(), ShardId::of(1), Workspace::results());

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
        ->run(Planned::oneShard(), ShardId::of(1), Workspace::results());
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
        ->run(Planned::twoShards(), ShardId::of(1), Workspace::results());
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
        ->run(Planned::twoShards(), ShardId::of(2), Workspace::results());
    $result = $resultIn($project, 2);

    expect($result instanceof ShardResult ? $result->flaky() : $result)->toEqual(MutantIds::none());
});
