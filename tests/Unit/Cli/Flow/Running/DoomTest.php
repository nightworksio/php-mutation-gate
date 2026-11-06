<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\Setup;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Config\Budget;
use NightWorksIO\MutationGate\Config\Equivalence;
use NightWorksIO\MutationGate\Config\Reach;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Doomed;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Fakes\CostModelFake;
use NightWorksIO\MutationGate\Tests\Fakes\StaticCheckerFake;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Dooms;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\RecordingChecker;
use NightWorksIO\MutationGate\Tests\Support\RunningCases;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use NightWorksIO\MutationGate\Tests\Support\TickingClock;

afterEach(function (): void {
    Scratch::sweep();
});

$makeUnjudged = static fn(): Closure => RunningCases::unjudged(...);
$makeResultIn = static fn(): Closure => RunningCases::resultIn(...);
$makeDoomable = static fn(): Closure => RunningCases::doomable(...);
$makeOnPullRequest = static fn(): Closure => RunningCases::onPullRequest(...);
$makeFloored = static fn(): Closure => RunningCases::floored(...);
$makeAsked = static fn(): Closure => RunningCases::asked(...);
$makeDoomOf = static fn(): Closure => RunningCases::doomOf(...);
$makeMoneySurvivor = static fn(): Closure => RunningCases::moneySurvivor(...);
$makeTicking = static fn(): Closure => static function (): Setup {
    $setup = Flows::setup();

    return new Setup($setup->configFile, $setup->gate, $setup->installed, new TickingClock(Configs::NOW, 3), $setup->memory, $setup->config, $setup->command);
};

it('stops a pull request\'s shard after the chunk whose survivor makes its run certain to fail, leaving the rest unjudged', function () use (
    $makeDoomable,
    $makeResultIn,
    $makeUnjudged,
    $makeOnPullRequest,
    $makeFloored,
    $makeAsked,
    $makeDoomOf,
    $makeMoneySurvivor,
): void {
    $doomable = $makeDoomable();
    $resultIn = $makeResultIn();
    $unjudged = $makeUnjudged();
    $onPullRequest = $makeOnPullRequest();
    $floored = $makeFloored();
    $asked = $makeAsked();
    $doomOf = $makeDoomOf();
    $moneySurvivor = $makeMoneySurvivor();

    $project = Flows::project();
    $runner = ScriptedRunner::fixture();

    new Running(Flows::adapters($project, [], $runner, $floored(Floor::whole())), $doomable(), Flows::setup())
        ->run(Planned::handedIn($project, $onPullRequest(Planned::oneShard())), ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);

    expect($asked($runner))->toBe([['src/Money.php']])
        ->and($unjudged($result))->toBe(['src/Held.php'])
        ->and($doomOf($result))->toBe(['src/Money.php', $moneySurvivor(), 'src', 10_000, 'tree', 'stopped']);
});

it('stops a merge group\'s shard, as a pull request\'s, after the chunk whose survivor makes its run certain to fail', function () use (
    $makeDoomable,
    $makeResultIn,
    $makeUnjudged,
    $makeFloored,
    $makeAsked,
): void {
    $doomable = $makeDoomable();
    $resultIn = $makeResultIn();
    $unjudged = $makeUnjudged();
    $floored = $makeFloored();
    $asked = $makeAsked();

    $project = Flows::project();
    $runner = ScriptedRunner::fixture();
    $group = Planned::oneShard()->on(RunOn::mergeGroup(Revision::ref('base'), Scope::branch('main')));

    new Running(Flows::adapters($project, [], $runner, $floored(Floor::whole())), $doomable(), Flows::setup())
        ->run(Planned::handedIn($project, $group), ShardId::of(1), Workspace::results());

    expect($asked($runner))->toBe([['src/Money.php']])
        ->and($unjudged($resultIn($project, 1)))->toBe(['src/Held.php']);
});

it('runs a pull request\'s shard to its end past a survivor that makes its run certain to fail, noting it, where the config marks it by default', function () use (
    $makeResultIn,
    $makeUnjudged,
    $makeOnPullRequest,
    $makeFloored,
    $makeAsked,
    $makeDoomOf,
): void {
    $resultIn = $makeResultIn();
    $unjudged = $makeUnjudged();
    $onPullRequest = $makeOnPullRequest();
    $floored = $makeFloored();
    $asked = $makeAsked();
    $doomOf = $makeDoomOf();

    $project = Flows::project();
    $runner = ScriptedRunner::fixture();

    new Running(Flows::adapters($project, [], $runner, $floored(Floor::whole())), Flows::settings(Equivalence::notProvenStatically()), Flows::setup())
        ->run(Planned::handedIn($project, $onPullRequest(Planned::oneShard())), ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);

    $every = array_merge(...$asked($runner));
    sort($every);

    expect($every)->toBe(['src/Held.php', 'src/Money.php'])
        ->and($unjudged($result))->toBe([])
        ->and($doomOf($result))->toBe(['src/Held.php', '8705b7dc7d27', 'src', 10_000, 'tree', 'judged on']);
});

it('notes no survivor where the config turns fail-fast off, and runs the shard to its end', function () use (
    $makeResultIn,
    $makeUnjudged,
    $makeOnPullRequest,
    $makeFloored,
    $makeDoomOf,
): void {
    $resultIn = $makeResultIn();
    $project = Flows::project();
    $settings = Flows::settings(Equivalence::notProvenStatically(), Reach::failFastOff());

    new Running(Flows::adapters($project, [], ScriptedRunner::fixture(), $makeFloored()(Floor::whole())), $settings, Flows::setup())
        ->run(Planned::handedIn($project, $makeOnPullRequest()(Planned::oneShard())), ShardId::of(1), Workspace::results());

    expect($makeUnjudged()($resultIn($project, 1)))->toBe([])
        ->and($makeDoomOf()($resultIn($project, 1)))->toBe('undoomed');
});

it('runs a pull request\'s shard to its end in chunks, in the plan\'s order, while no survivor makes its run certain to fail', function () use (
    $makeDoomable,
    $makeResultIn,
    $makeUnjudged,
    $makeOnPullRequest,
    $makeFloored,
    $makeAsked,
    $makeDoomOf,
): void {
    $doomable = $makeDoomable();
    $resultIn = $makeResultIn();
    $unjudged = $makeUnjudged();
    $onPullRequest = $makeOnPullRequest();
    $floored = $makeFloored();
    $asked = $makeAsked();
    $doomOf = $makeDoomOf();

    $project = Flows::project();
    $runner = ScriptedRunner::fixture();

    new Running(Flows::adapters($project, [], $runner, $floored(Floor::of(50))), $doomable(), Flows::setup())
        ->run(Planned::handedIn($project, $onPullRequest(Planned::oneShard())), ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);

    expect($asked($runner))->toBe([['src/Money.php'], ['src/Held.php']])
        ->and($unjudged($result))->toBe([])
        ->and($doomOf($result))->toBe('undoomed');
});

it('runs a shard off a pull request to its end, whatever its survivors', function () use (
    $makeDoomable,
    $makeResultIn,
    $makeUnjudged,
    $makeFloored,
    $makeAsked,
    $makeDoomOf,
): void {
    $doomable = $makeDoomable();
    $resultIn = $makeResultIn();
    $unjudged = $makeUnjudged();
    $floored = $makeFloored();
    $asked = $makeAsked();
    $doomOf = $makeDoomOf();

    $project = Flows::project();
    $runner = ScriptedRunner::fixture();

    new Running(Flows::adapters($project, [], $runner, $floored(Floor::whole())), $doomable(), Flows::setup())
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);

    expect($asked($runner))->toBe([['src/Held.php'], ['src/Money.php']])
        ->and($unjudged($result))->toBe([])
        ->and($doomOf($result))->toBe('undoomed');
});

it('runs a pull request\'s shard to its end where its survivor proves flaky', function () use (
    $makeDoomable,
    $makeResultIn,
    $makeUnjudged,
    $makeOnPullRequest,
    $makeFloored,
    $makeAsked,
    $makeDoomOf,
): void {
    $doomable = $makeDoomable();
    $resultIn = $makeResultIn();
    $unjudged = $makeUnjudged();
    $onPullRequest = $makeOnPullRequest();
    $floored = $makeFloored();
    $asked = $makeAsked();
    $doomOf = $makeDoomOf();

    $project = Flows::project();
    $runner = ScriptedRunner::fixture()->killingAgain();

    new Running(Flows::adapters($project, [], $runner, $floored(Floor::whole())), $doomable(), Flows::setup())
        ->run(Planned::handedIn($project, $onPullRequest(Planned::oneShard())), ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);

    expect($asked($runner))->toBe([['src/Money.php'], ['src/Held.php']])
        ->and($unjudged($result))->toBe([])
        ->and($doomOf($result))->toBe('undoomed');
});

it('runs a pull request\'s shard to its end where its trees cannot be read, or it holds no tree to a floor', function (object $port) use (
    $makeDoomable,
    $makeResultIn,
    $makeUnjudged,
    $makeOnPullRequest,
    $makeAsked,
    $makeDoomOf,
): void {
    $doomable = $makeDoomable();
    $resultIn = $makeResultIn();
    $unjudged = $makeUnjudged();
    $onPullRequest = $makeOnPullRequest();
    $asked = $makeAsked();
    $doomOf = $makeDoomOf();

    $project = Flows::project();
    $runner = ScriptedRunner::fixture();

    new Running(Flows::adapters($project, [], $runner, $port), $doomable(), Flows::setup())
        ->run(Planned::handedIn($project, $onPullRequest(Planned::oneShard())), ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);

    expect($asked($runner))->toBe([['src/Held.php'], ['src/Money.php']])
        ->and($unjudged($result))->toBe([])
        ->and($doomOf($result))->toBe('undoomed');
})->with([
    'unreadable trees' => [fn(): TreeSourceFake => new TreeSourceFake(CannotJudge::because('No composer.json.'))],
    'security mutators alone' => [fn(): Narrowing => Narrowing::none()->toMutators(Mutators::named('Plus'))],
    'one suite\'s tests alone' => [fn(): Narrowing => Narrowing::none()->toSuite(SuiteName::of('unit'))],
]);

it('chunks a pull request\'s shard in about fifteen minutes of the work the cost model expects, each chunk at least one unit', function (float $each, array $chunks) use (
    $makeDoomable,
    $makeOnPullRequest,
    $makeFloored,
    $makeAsked,
): void {
    $doomable = $makeDoomable();
    $onPullRequest = $makeOnPullRequest();
    $floored = $makeFloored();
    $asked = $makeAsked();

    $project = Flows::project();
    $runner = ScriptedRunner::fixture();
    $files = Units::of(Planned::money(), Unit::file(Path::of('src/Second.php')), Unit::file(Path::of('src/Third.php')));
    $plan = $onPullRequest(Planned::of(Shard::of(ShardId::of(1), Package::at(Path::root()), $files, Seconds::of(3.0), 'files')));

    new Running(
        Flows::adapters($project, [], $runner, $floored(Floor::of(50)), new CostModelFake(Seconds::of($each))),
        $doomable(),
        Flows::setup(),
    )->run(Planned::handedIn($project, $plan), ShardId::of(1), Workspace::results());

    expect($asked($runner))->toBe($chunks);
})->with([
    'each ten minutes' => [600.0, [['src/Money.php'], ['src/Second.php'], ['src/Third.php']]],
    'each four minutes' => [240.0, [['src/Money.php', 'src/Second.php', 'src/Third.php']]],
    'each six minutes' => [360.0, [['src/Money.php', 'src/Second.php'], ['src/Third.php']]],
]);

it('runs no shard of a plan in one process after one that stopped once its run could not pass', function () use (
    $makeDoomable,
    $makeResultIn,
    $makeOnPullRequest,
    $makeFloored,
    $makeAsked,
): void {
    $doomable = $makeDoomable();
    $resultIn = $makeResultIn();
    $onPullRequest = $makeOnPullRequest();
    $floored = $makeFloored();
    $asked = $makeAsked();

    $project = Flows::project();
    $runner = ScriptedRunner::fixture();

    $written = new Running(Flows::adapters($project, [], $runner, $floored(Floor::whole())), $doomable(), Flows::setup())
        ->runAll(Planned::handedIn($project, $onPullRequest(Planned::twoShards())), Workspace::results());

    expect($written)->toBeInstanceOf(Written::class)
        ->and($asked($runner))->toBe([['src/Money.php']])
        ->and($resultIn($project, 1))->toBeInstanceOf(ShardResult::class)
        ->and(is_file(sprintf('%s/.mutation-gate/results/2.json', $project)))->toBeFalse();
});

it('runs every shard of a plan in one process past one that judged on once its run could not pass', function () use (
    $makeTicking,
    $makeResultIn,
    $makeOnPullRequest,
    $makeFloored,
    $makeAsked,
    $makeDoomOf,
): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture();
    $settings = Flows::settings(Equivalence::notProvenStatically());

    $written = new Running(Flows::adapters($project, [], $runner, $makeFloored()(Floor::whole())), $settings, $makeTicking()())
        ->runAll(Planned::handedIn($project, $makeOnPullRequest()(Planned::twoShards())), Workspace::results());
    $first = $makeResultIn()($project, 1);

    expect($written)->toBeInstanceOf(Written::class)
        ->and($makeAsked()($runner))->toBe([['src/Money.php'], ['src/Held.php']])
        ->and($makeDoomOf()($first)[5] ?? '')->toBe('judged on')
        ->and($first instanceof ShardResult && $first->doomed() instanceof Doomed ? $first->doomed()->after()->seconds() : 0.0)->toBeGreaterThan(0.0)
        ->and($makeResultIn()($project, 2))->toBeInstanceOf(ShardResult::class);
});

it('runs a pull request\'s shard with a budget to its end in batches past a survivor it judges on', function () use (
    $makeTicking,
    $makeResultIn,
    $makeUnjudged,
    $makeOnPullRequest,
    $makeFloored,
    $makeAsked,
    $makeDoomOf,
): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture();
    $settings = Flows::settings(Equivalence::notProvenStatically(), Budget::of('1h'));

    new Running(Flows::adapters($project, [], $runner, $makeFloored()(Floor::whole())), $settings, $makeTicking()())
        ->run(Planned::handedIn($project, $makeOnPullRequest()(Planned::oneShard())), ShardId::of(1), Workspace::results());
    $result = $makeResultIn()($project, 1);
    $every = array_merge(...$makeAsked()($runner));
    sort($every);

    expect($every)->toBe(['src/Held.php', 'src/Money.php'])
        ->and($makeUnjudged()($result))->toBe([])
        ->and($makeDoomOf()($result)[5] ?? '')->toBe('judged on')
        ->and($result instanceof ShardResult && $result->doomed() instanceof Doomed ? $result->doomed()->after()->seconds() : 0.0)->toBeGreaterThan(0.0);
});

/** An analyser that answers each of these survivors' checks with what it finds, and finds nothing in the originals. */
function doomChecker(string $project, Findings ...$found): RecordingChecker
{
    $answers = [];

    foreach (['src/Money.php', 'src/Held.php'] as $at => $file) {
        $answers[Workspace::checkedMutant(Dooms::survivor($file)->id())->value()] = $found[$at] ?? Findings::none();
    }

    return new RecordingChecker(new StaticCheckerFake(AnalyserIdentity::of('fake', '1.0.0', Digest::sha256Of('')), Findings::none(), $answers), $project);
}

it('stops a pull request\'s shard after a chunk whose survivor static analysis does not clear, checking each survivor once', function () use (
    $makeDoomable,
    $makeResultIn,
    $makeUnjudged,
    $makeOnPullRequest,
    $makeFloored,
    $makeAsked,
    $makeDoomOf,
    $makeMoneySurvivor,
): void {
    $doomable = $makeDoomable();
    $resultIn = $makeResultIn();
    $unjudged = $makeUnjudged();
    $onPullRequest = $makeOnPullRequest();
    $floored = $makeFloored();
    $asked = $makeAsked();
    $doomOf = $makeDoomOf();
    $moneySurvivor = $makeMoneySurvivor();

    $project = Flows::project();
    $runner = ScriptedRunner::fixture();
    $checker = doomChecker($project);

    new Running(Flows::adapters($project, [], $runner, $floored(Floor::whole()), $checker), $doomable(), Flows::setup())
        ->run(Planned::handedIn($project, $onPullRequest(Planned::oneShard())), ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);

    expect($asked($runner))->toBe([['src/Money.php']])
        ->and($unjudged($result))->toBe(['src/Held.php'])
        ->and($doomOf($result))->toBe(['src/Money.php', $moneySurvivor(), 'src', 10_000, 'tree', 'stopped'])
        ->and(array_map(static fn(array $check): string => $check[0], $checker->asked()))->toBe(['src/Money.php'])
        ->and($checker->warmUps())->toHaveCount(1);
});

it('runs a pull request\'s shard on past each survivor static analysis kills, checking each once and the analyser warmed up once', function () use (
    $makeDoomable,
    $makeResultIn,
    $makeUnjudged,
    $makeOnPullRequest,
    $makeFloored,
    $makeAsked,
    $makeDoomOf,
): void {
    $doomable = $makeDoomable();
    $resultIn = $makeResultIn();
    $unjudged = $makeUnjudged();
    $onPullRequest = $makeOnPullRequest();
    $floored = $makeFloored();
    $asked = $makeAsked();
    $doomOf = $makeDoomOf();

    $project = Flows::project();
    $runner = ScriptedRunner::fixture();
    $error = static fn(string $file): Findings => Findings::of(Finding::error(Path::of($file), 'return.type', 'It returns no bool.'));
    $checker = doomChecker($project, $error('src/Money.php'), $error('src/Held.php'));

    new Running(Flows::adapters($project, [], $runner, $floored(Floor::whole()), $checker), $doomable(), Flows::setup())
        ->run(Planned::handedIn($project, $onPullRequest(Planned::oneShard())), ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);

    expect($asked($runner))->toBe([['src/Money.php'], ['src/Held.php']])
        ->and($unjudged($result))->toBe([])
        ->and($doomOf($result))->toBe('undoomed')
        ->and(RunningCases::statuses($result))->toContain(sprintf('%s killed-by-static-analysis', Dooms::survivor('src/Money.php')->nativeId()))
        ->and(array_map(static fn(array $check): string => $check[0], $checker->asked()))->toBe(['src/Money.php', 'src/Held.php'])
        ->and($checker->warmUps())->toHaveCount(1);
});

it('runs a pull request\'s shard on past a survivor whose static check could not run, which a check may yet kill', function () use (
    $makeDoomable,
    $makeResultIn,
    $makeUnjudged,
    $makeOnPullRequest,
    $makeFloored,
    $makeAsked,
    $makeDoomOf,
): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture();
    $answers = [];

    foreach (['src/Money.php', 'src/Held.php'] as $file) {
        $answers[Workspace::checkedMutant(Dooms::survivor($file)->id())->value()] = CannotJudge::because('The analyser crashed.');
    }

    $checker = new RecordingChecker(new StaticCheckerFake(AnalyserIdentity::of('fake', '1.0.0', Digest::sha256Of('')), Findings::none(), $answers), $project);

    new Running(Flows::adapters($project, [], $runner, $makeFloored()(Floor::whole()), $checker), $makeDoomable()(), Flows::setup())
        ->run(Planned::handedIn($project, $makeOnPullRequest()(Planned::oneShard())), ShardId::of(1), Workspace::results());
    $result = $makeResultIn()($project, 1);

    expect($makeAsked()($runner))->toBe([['src/Money.php'], ['src/Held.php']])
        ->and($makeUnjudged()($result))->toBe([])
        ->and($makeDoomOf()($result))->toBe('undoomed');
});
