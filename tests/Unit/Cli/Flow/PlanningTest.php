<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Handoff;
use NightWorksIO\MutationGate\Cli\Flow\Mode;
use NightWorksIO\MutationGate\Cli\Flow\Planning;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Config\Ignores;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Marker;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Timing;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Reasons;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\CostModelFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\CoverageAsked;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

$coverage = static fn(): CoverageRun => CoverageRun::of(WholeSuite::tests(), Workspace::coverage());

$money = Unit::file(Path::of('src/Money.php'));
$held = Unit::held(Path::of('src/Held.php'), Group::named('holds:src/Held.php'));

/** The units each shard of a plan runs, by the shard's number. */
$shards = static function (Plan|CannotJudge $plan): array {
    $units = [];

    foreach ($plan instanceof Plan ? $plan : [] as $shard) {
        $units[$shard->id()->number()] = [...$shard->units()];
    }

    return $plan instanceof Plan ? $units : [$plan->why()];
};

/** A plan over the project, with these ports in place of the fakes. */
$plan = (static fn(string $project, Mode $mode, Cut $cut, object ...$ports): Plan|CannotJudge => new Planning(
    Flows::adapters($project, [], ...$ports),
    Flows::settings(),
    Flows::setup(),
)->plan($mode, $coverage(), $cut));

it('plans every unit of a full run into shards, on the commit HEAD is at', function () use (
    $plan,
    $shards,
    $money,
    $held,
): void {
    $planned = $plan(Flows::project(), Mode::full(), Cut::exactly(2));

    expect($shards($planned))->toEqual([1 => [$held], 2 => [$money]])
        ->and($planned instanceof Plan ? $planned->commit() : $planned)->toEqual(Revision::ref(Flows::HEAD))
        ->and($planned instanceof Plan ? $planned->runOn() : $planned)
        ->toEqual(RunOn::at(Scope::branch('main'), Scope::branch('main')))
        ->and($planned instanceof Plan ? $planned->keys()->units() : $planned)
        ->toEqual(Paths::of(Path::of('src/Held.php'), Path::of('src/Money.php')))
        ->and($planned instanceof Plan ? $planned->keys()->keyOf(Path::of('src/Money.php')) : $planned)
        ->toBeInstanceOf(Digest::class)
        ->and($planned instanceof Plan ? $planned->changed() : $planned)->toEqual(Changes::none())
        ->and($planned instanceof Plan ? $planned->reach() : $planned)
        ->toEqual(Reasons::of(Reason::that('A full run considers every unit.')))
        ->and($planned instanceof Plan ? $planned->proved() : $planned)->toEqual(Units::none())
        ->and($planned instanceof Plan ? $planned->carried() : $planned)->toEqual(Units::none());
});

it('hands each shard the map of its own files', function () use ($plan): void {
    $project = Flows::project();
    $map = CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(3), TestId::of('MoneyTest::adds'))
        ->covered(Path::of('src/Held.php'), Line::of(4), TestId::of('HeldTest::doubles'));
    $plan($project, Mode::full(), Cut::exactly(2), new CoverageAsked(RunnerFake::ofTheFixture(), $map));
    $handed = new Handoff(Flows::adapters($project)->project);

    expect($handed->read(ShardId::of(1)))
        ->toEqual(CoverageMapFile::decode(CoverageMapFile::encode($map->onlyFor(Paths::of(Path::of('src/Held.php'))))))
        ->and($handed->read(ShardId::of(2)))
        ->toEqual(CoverageMapFile::decode(
            CoverageMapFile::encode($map->onlyFor(Paths::of(Path::of('src/Money.php')))),
        ));
});

it('asks for coverage withholding what every process that runs the project\'s code withholds', function () use (
    $plan,
): void {
    $runner = new CoverageAsked(RunnerFake::ofTheFixture(), CoverageMap::empty());
    $plan(Flows::project(), Mode::full(), Cut::exactly(1), $runner);

    expect($runner->asked())->toHaveCount(1)
        ->and($runner->ran()[0]->withheld())->toEqual(Withheld::standard()->and(Withheld::of('FAKE_CI_TOKEN')))
        ->and($runner->ran()[0]->tests())->toEqual(WholeSuite::tests());
});

it('weighs each unit by what the cost model expects of it, with what the ledgers learned', function () use (
    $plan,
    $shards,
    $money,
    $held,
): void {
    $store = new ProofStoreFake();
    $store->write(
        Scope::branch('main'),
        Ledger::empty()->withTiming(
            Timing::of(Path::of('src/Held.php'), Seconds::of(50.0), 'fake', Moment::at('2026-09-30T10:00:00Z')),
        ),
    );

    expect($shards($plan(Flows::project(), Mode::full(), Cut::bySize(10, 10), new CostModelFake(Seconds::of(1.0)))))
        ->toEqual([1 => [$held, $money]])
        ->and($shards($plan(Flows::project(), Mode::full(), Cut::bySize(10, 10), new CostModelFake(Seconds::of(8.0)))))
        ->toHaveCount(2)
        ->and($shards($plan(Flows::project(), Mode::full(), Cut::bySize(10, 10), $store)))
        ->toHaveCount(2);
});

it('drops every unit a proof with a matching key covers, and carries what the change does not reach', function () use (
    $plan,
    $shards,
    $money,
    $held,
): void {
    $project = Flows::project();
    $full = $plan($project, Mode::full(), Cut::exactly(1));
    $key = $full instanceof Plan ? $full->keys()->keyOf(Path::of('src/Money.php')) : $full;
    $base = $full instanceof Plan ? $full->base() : Digest::of('none');
    $run = Run::of('local', Moment::at('2026-09-30T10:00:00Z'), $base);
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), Ledger::empty()
        ->withProof(Proof::of(
            $key instanceof Digest ? $key : Digest::of('none'),
            Path::of('src/Money.php'),
            Mutants::none(),
            $run,
        ))
        ->withProof(Proof::of(Digest::of('old'), Path::of('src/Held.php'), Mutants::none(), $run)));
    $checkout = new ChangeSourceFake(
        Revision::ref('base'),
        Changes::of(Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(2)))),
        [Revision::workingTree()->name() => Flows::FILES, 'base' => Flows::FILES],
    );
    $scoped = $plan($project, Mode::since('base'), Cut::exactly(1), $store, $checkout);

    expect($shards($scoped))->toEqual([1 => []])
        ->and($scoped instanceof Plan ? $scoped->proved() : $scoped)->toEqual(Units::of($money))
        ->and($scoped instanceof Plan ? $scoped->carried() : $scoped)->toEqual(Units::of($held))
        ->and($scoped instanceof Plan ? $scoped->changed() : $scoped)
        ->toEqual(Changes::of(Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(2)))))
        ->and($scoped instanceof Plan ? $scoped->base() : $scoped)->toEqual($base)
        ->and($scoped instanceof Plan ? $scoped->keys()->units() : $scoped)
        ->toEqual(Paths::of(Path::of('src/Money.php')));
});

it('cannot plan where the units cannot be found, the coverage taken or the run keyed', function (
    object $port,
    string $why,
) use ($plan): void {
    expect($plan(Flows::project(), Mode::full(), Cut::exactly(1), $port))->toEqual(CannotJudge::because($why));
})->with([
    'the units' => [
        new TreeSourceFake(CannotJudge::because('No tree is declared.')),
        'No tree is declared.',
    ],
    'the coverage' => [
        new CoverageAsked(RunnerFake::ofTheFixture(), CannotJudge::because('The suite failed.')),
        'The suite failed.',
    ],
    'the keys' => [
        new RunnerFake(
            CannotJudge::because('The runner is not installed.'),
            Groups::of(),
            CoverageMap::empty(),
            Mutants::none(),
            Paths::none(),
            Paths::none(),
            TestNames::none(),
            Paths::none(),
        ),
        'The runner is not installed.',
    ],
]);

it('cannot plan more packages than the shards asked for', function () use ($plan): void {
    $trees = Trees::of(
        Tree::at(Path::of('src'), Floor::of(50), Package::at(Path::root())),
        Tree::at(Path::of('packages/a/src'), Floor::of(50), Package::at(Path::of('packages/a'))),
    );
    $checkout = new ChangeSourceFake(Revision::ref('base'), Changes::none(), [
        Revision::workingTree()->name() => [...Flows::FILES, 'packages/a/src/Limit.php' => "<?php\n"],
    ]);

    expect($plan(Flows::project(), Mode::full(), Cut::exactly(1), new TreeSourceFake($trees), $checkout))
        ->toEqual(CannotJudge::because(
            '--shards=1 cannot hold 2 packages, because packages never share a shard. Ask for 2 shards or more.',
        ));
});

it('cannot plan a shard of a package other than the project\'s root', function () use ($plan): void {
    $trees = Trees::of(
        Tree::at(Path::of('src'), Floor::of(50), Package::at(Path::root())),
        Tree::at(Path::of('packages/a/src'), Floor::of(50), Package::at(Path::of('packages/a'))),
    );
    $checkout = new ChangeSourceFake(Revision::ref('base'), Changes::none(), [
        Revision::workingTree()->name() => [...Flows::FILES, 'packages/a/src/Limit.php' => "<?php\n"],
    ]);

    expect($plan(Flows::project(), Mode::full(), Cut::exactly(2), new TreeSourceFake($trees), $checkout))
        ->toEqual(CannotJudge::because(<<<'SAID'
            The package at packages/a has units to mutate, and the runner runs the suite of the project's root alone,
            which does not judge another package's code, so their mutants cannot be judged.
            SAID));
});

it('cannot plan where it cannot hand a shard its map', function () use ($plan): void {
    $project = Flows::project();
    Scratch::write($project, '.mutation-gate/coverage/shard-1/map.json.gz/blocked', '');

    expect($plan($project, Mode::full(), Cut::exactly(1)))->toEqual(CannotJudge::because(
        sprintf('%s/.mutation-gate/coverage/shard-1/map.json.gz could not be written.', $project),
    ));
});

it('hands a full pull request plan the lines changed since the default branch, for new code', function () use (
    $plan,
): void {
    $pullRequest = new CiPlanFake(ShardId::of(1), RunOn::at(Scope::pullRequest(7), Scope::branch('main')));
    $changed = Changes::of(Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(3))));
    $checkout = new ChangeSourceFake(Revision::ref(Flows::MAIN), $changed, [
        Revision::workingTree()->name() => Flows::FILES,
        Flows::MAIN => Flows::FILES,
    ]);

    $planned = $plan(Flows::project(), Mode::full(), Cut::exactly(1), $pullRequest, $checkout);

    expect($planned instanceof Plan ? $planned->changed() : $planned)->toEqual($changed);
});

it('cannot plan a full pull request run where git cannot tell what changed since the default branch', function () use (
    $plan,
): void {
    $pullRequest = new CiPlanFake(ShardId::of(1), RunOn::at(Scope::pullRequest(7), Scope::branch('main')));

    expect($plan(Flows::project(), Mode::full(), Cut::exactly(1), $pullRequest))->toEqual(CannotJudge::because(sprintf(
        "%s %s\n%s\n%s",
        'A pull request\'s new code is judged against refs/remotes/origin/main,',
        'and git cannot tell what changed since it,',
        'so no floor for new code can be held. refs/remotes/origin/main is not a revision this repository has.',
        'Fetch the default branch into the checkout before the plan.',
    )));
});

it('cannot plan where the runner has its own ignore markers in what it would mutate, listing each', function () use (
    $plan,
): void {
    $marked = ScriptedRunner::fixture()->marking(Markers::of(
        Marker::inSource(
            Path::of('src/Money.php'),
            Line::of(9),
            '@pest-mutate-ignore',
            Enclosing::named(Path::of('src/Money.php'), 'add'),
        ),
        Marker::of('infection.json5 mutators.global-ignore', 'ignore', '{"path": "src/Held.php", "reason": "…"}'),
    ));

    expect($plan(Flows::project(), Mode::full(), Cut::exactly(1), $marked))->toEqual(CannotJudge::because(<<<'SAID'
        The runner's own ignore markers hide mutants with no reason and no end,
        so the run cannot go ahead:
          src/Money.php:9 in add(), @pest-mutate-ignore
            replaced by {"mutant": "<the id of each mutant it hides>", "reason": "<why no test can tell>"}
          infection.json5 mutators.global-ignore, ignore
            replaced by {"path": "src/Held.php", "reason": "…"}
        Replace each with its entry in ignores.entries,
        or set ignores.native: allow while the project moves them there.
        SAID));
});

it('plans with the runner\'s own markers where ignores.native allows them, or with none to find', function (
    ScriptedRunner $runner,
): void {
    $planned = new Planning(
        Flows::adapters(Flows::project(), [], $runner),
        Flows::settings(Ignores::allowingNativeMarkers()),
        Flows::setup(),
    )->plan(Mode::full(), CoverageRun::of(WholeSuite::tests(), Workspace::coverage()), Cut::exactly(1));

    expect($planned)->toBeInstanceOf(Plan::class);
})->with([
    'markers allowed' => [ScriptedRunner::fixture()->marking(Markers::of(
        Marker::inSource(Path::of('src/Money.php'), Line::of(9), 'x', Nameless::code()),
    ))],
    'no markers' => [ScriptedRunner::fixture()->marking(Markers::none())],
]);

it('cannot plan where the runner cannot look for its own ignore markers', function () use ($plan): void {
    $blind = ScriptedRunner::fixture()->marking(CannotJudge::because('infection.json5 cannot be read.'));

    expect($plan(Flows::project(), Mode::full(), Cut::exactly(1), $blind))
        ->toEqual(CannotJudge::because('infection.json5 cannot be read.'));
});
