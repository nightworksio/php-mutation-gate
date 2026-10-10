<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Fakes\CostModelFake;
use NightWorksIO\MutationGate\Tests\Fakes\StaticCheckerFake;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\RecordingChecker;
use NightWorksIO\MutationGate\Tests\Support\RunningCases;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

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
        ->and($doomOf($result))->toBe(['src/Money.php', $moneySurvivor(), 'src', 10_000, 'tree']);
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

it('chunks a pull request\'s shard in about two minutes of the work the cost model expects, each chunk at least one unit', function (float $each, array $chunks) use (
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
    'each a minute and a half' => [90.0, [['src/Money.php'], ['src/Second.php'], ['src/Third.php']]],
    'each half a minute' => [30.0, [['src/Money.php', 'src/Second.php', 'src/Third.php']]],
    'each a minute' => [50.0, [['src/Money.php', 'src/Second.php'], ['src/Third.php']]],
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

/** The fixture's one survivor of a file, as the shard's runner reports it. */
function doomSurvivor(string $file): Mutant
{
    foreach (Flows::mutantsOf($file) as $mutant) {
        if ($mutant->status() === MutantStatus::Survived) {
            return $mutant;
        }
    }

    throw new LogicException(sprintf('No survivor in %s.', $file));
}

/** An analyser that answers each of these survivors' checks with what it finds, and finds nothing in the originals. */
function doomChecker(string $project, Findings ...$found): RecordingChecker
{
    $answers = [];

    foreach (['src/Money.php', 'src/Held.php'] as $at => $file) {
        $answers[Workspace::checkedMutant(doomSurvivor($file)->id())->value()] = $found[$at] ?? Findings::none();
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
        ->and($doomOf($result))->toBe(['src/Money.php', $moneySurvivor(), 'src', 10_000, 'tree'])
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
        ->and(RunningCases::statuses($result))->toContain(sprintf('%s killed-by-static-analysis', doomSurvivor('src/Money.php')->nativeId()))
        ->and(array_map(static fn(array $check): string => $check[0], $checker->asked()))->toBe(['src/Money.php', 'src/Held.php'])
        ->and($checker->warmUps())->toHaveCount(1);
});

it('runs a pull request\'s shard on past a survivor proven equivalent, to the chunk whose survivor is not', function () use (
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
    $runner = ScriptedRunner::fixture()->checking(Checkable::inPlace(Contents::of("<?php\n\nfinal  class Money\n{\n}\n")));

    new Running(Flows::adapters($project, [], $runner, $floored(Floor::whole())), Flows::settings(), Flows::setup())
        ->run(Planned::handedIn($project, $onPullRequest(Planned::oneShard())), ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);

    expect($asked($runner))->toBe([['src/Money.php'], ['src/Held.php']])
        ->and($unjudged($result))->toBe([])
        ->and($doomOf($result))->toBe(['src/Held.php', doomSurvivor('src/Held.php')->id()->value(), 'src', 10_000, 'tree']);
});
