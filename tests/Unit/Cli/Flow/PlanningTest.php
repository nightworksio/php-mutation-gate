<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Handoff;
use NightWorksIO\MutationGate\Cli\Flow\Inventory;
use NightWorksIO\MutationGate\Cli\Flow\KeptCoverage;
use NightWorksIO\MutationGate\Cli\Flow\Mode;
use NightWorksIO\MutationGate\Cli\Flow\Planning;
use NightWorksIO\MutationGate\Cli\Flow\Setup;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Config\Ignores;
use NightWorksIO\MutationGate\Config\Runner as ConfiguredRunner;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Cost\CostBasis;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\Coverage\EntryKeys;
use NightWorksIO\MutationGate\Core\Coverage\KeptMap;
use NightWorksIO\MutationGate\Core\Coverage\MapLimits;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Matrix\NotFull;
use NightWorksIO\MutationGate\Core\Mutant\Marker;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\Kills;
use NightWorksIO\MutationGate\Core\Order\Ranking;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Timing;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Proof\Uncommitted;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Reasons;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Runner\RunnerBehaviour;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;
use NightWorksIO\MutationGate\Port\Repository;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\CostModelFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\RepositoryFake;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\CoverageAsked;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\HandedMaps;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\NamesAsked;
use NightWorksIO\MutationGate\Tests\Support\PeakMemoryFake;
use NightWorksIO\MutationGate\Tests\Support\Planned;
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
$plan = (static fn(string $project, Mode $mode, Cut $cut, object ...$ports): Plan|CannotJudge => Planned::from(new Planning(
    Flows::adapters($project, [], ...$ports),
    Flows::settings(),
    Flows::setup(),
)->plan($mode, $coverage(), $cut, MatrixKind::FirstKiller)));

/** A plan over the project of a run that records this much of the kill matrix, with these ports in place of the fakes. */
$recordingPlan = (static fn(string $project, Mode $mode, MatrixKind $matrix, object ...$ports): Plan|CannotJudge => Planned::from(new Planning(
    Flows::adapters($project, [], ...$ports),
    Flows::settings(),
    Flows::setup(),
)->plan($mode, $coverage(), Cut::exactly(1), $matrix)));

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
        ->and($planned instanceof Plan ? $planned->considered()->changed() : $planned)->toEqual(Changes::none())
        ->and($planned instanceof Plan ? $planned->considered()->reach() : $planned)
        ->toEqual(Reasons::of(Reason::that('A full run considers every unit.')))
        ->and($planned instanceof Plan ? $planned->considered()->proved() : $planned)->toEqual(Units::none())
        ->and($planned instanceof Plan ? $planned->considered()->carried() : $planned)->toEqual(Units::none());
});

/** A plan under a 512M cap, where the suite's coverage run held this much and the runner reads this config. */
$cappedPlan = static function (
    MemoryCap|NotGiven $peak,
    string $phpUnit = '',
    bool $handedOver = false,
    string $config = 'phpunit.xml',
    string $reads = 'phpunit.xml',
) use ($coverage): Plan|CannotJudge {
    $setup = Flows::setup();
    $project = Flows::project();

    if ($phpUnit !== '') {
        Scratch::write($project, $config, $phpUnit);
    }

    return Planned::from(new Planning(
        Flows::adapters($project, [], RunnerFake::ofTheFixture()->definedBy(Paths::of(Path::of($reads)))),
        Flows::settings(ConfiguredRunner::uses('fake')->cappedAt(MemoryCap::of(512, MemoryUnit::Megabytes))),
        new Setup($setup->configFile, $setup->gate, $setup->installed, $setup->clock, new PeakMemoryFake($peak)),
    )->plan(
        Mode::full(),
        $handedOver ? CoverageRead::from(Path::of('.mutation-gate/planned')) : $coverage(),
        Cut::exactly(2),
        MatrixKind::FirstKiller,
    ));
};

it('refuses to plan where the suite held more memory in its coverage run than the cap', function () use (
    $cappedPlan,
): void {
    expect($cappedPlan(MemoryCap::of(600, MemoryUnit::Megabytes)))->toEqual(CannotJudge::because(implode("\n", [
        'The largest process of the suite\'s coverage run held 600M resident, more than the 512M each mutant\'s',
        'process may hold, so its mutants cannot be judged under that cap. Resident memory counts more than',
        'memory_limit does. Raise runner.memory; doctor --measure says what the suite needs.',
    ])));
});

it('plans where the suite held no more than the cap, or the system did not count it', function (
    MemoryCap|NotGiven $peak,
) use ($cappedPlan): void {
    expect($cappedPlan($peak))->toBeInstanceOf(Plan::class);
})->with([
    'at the cap' => [MemoryCap::of(512, MemoryUnit::Megabytes)],
    'not counted' => [NotGiven::value()],
]);

it('plans where the project\'s own memory_limit lifts the cap over the suite, or none binds it', function (
    string $limit,
) use ($cappedPlan): void {
    $phpUnit = sprintf('<phpunit><php><ini name="memory_limit" value="%s"/></php></phpunit>', $limit);

    expect($cappedPlan(MemoryCap::of(600, MemoryUnit::Megabytes), $phpUnit))->toBeInstanceOf(Plan::class);
})->with(['none' => ['-1'], 'higher' => ['1G']]);

it('refuses where the project\'s own memory_limit is lower than the suite, weighing it against the cap', function () use (
    $cappedPlan,
): void {
    $phpUnit = '<phpunit><php><ini name="memory_limit" value="256M"/></php></phpunit>';

    expect($cappedPlan(MemoryCap::of(600, MemoryUnit::Megabytes), $phpUnit))->toBeInstanceOf(CannotJudge::class);
});

it('weighs the memory_limit of the PHPUnit config the runner reads, not one it does not', function () use (
    $cappedPlan,
): void {
    $phpUnit = '<phpunit><php><ini name="memory_limit" value="1G"/></php></phpunit>';

    $peak = MemoryCap::of(600, MemoryUnit::Megabytes);

    expect($cappedPlan($peak, $phpUnit, config: 'config/phpunit.xml', reads: 'config/phpunit.xml'))
        ->toBeInstanceOf(Plan::class)
        ->and($cappedPlan($peak, $phpUnit, config: 'phpunit.xml', reads: 'config/phpunit.xml'))
        ->toBeInstanceOf(CannotJudge::class);
});

it('records in the plan the peak its coverage run measured, for every shard\'s memory triage', function () use (
    $cappedPlan,
): void {
    $measured = $cappedPlan(MemoryCap::of(200, MemoryUnit::Megabytes));
    $uncounted = $cappedPlan(NotGiven::value());
    $handed = $cappedPlan(MemoryCap::of(200, MemoryUnit::Megabytes), handedOver: true);

    expect($measured instanceof Plan ? $measured->briefing()->peak() : $measured)->toEqual(MemoryCap::of(200, MemoryUnit::Megabytes))
        ->and($uncounted instanceof Plan ? $uncounted->briefing()->peak() : $uncounted)->toEqual(NotGiven::value())
        ->and($handed instanceof Plan ? $handed->briefing()->peak() : $handed)->toEqual(NotGiven::value());
});

it('briefs every shard and the verdict that a run narrowed to the security mutators makes mutants with those alone', function () use (
    $plan,
): void {
    $secured = $plan(Flows::project(), Mode::full(), Cut::exactly(2), Narrowing::none()->toMutators(Mutators::named('security/HashEqualsToTrue')));
    $whole = $plan(Flows::project(), Mode::full(), Cut::exactly(2));

    expect($secured instanceof Plan ? $secured->briefing()->isSecurityOnly() : $secured)->toBeTrue()
        ->and($whole instanceof Plan ? $whole->briefing()->isSecurityOnly() : $whole)->toBeFalse()
        ->and($secured instanceof Plan && $whole instanceof Plan ? $secured->base() : $secured)
        ->not->toEqual($whole instanceof Plan ? $whole->base() : $whole);
});

it('briefs every shard and the verdict that a run narrowed to one suite runs its tests alone, and covers that suite alone', function () use (
    $plan,
): void {
    $runner = new CoverageAsked(RunnerFake::ofTheFixture(), CoverageMap::empty());
    $suited = $plan(Flows::project(), Mode::full(), Cut::exactly(2), $runner, Narrowing::none()->toSuite(SuiteName::of('unit')));
    $whole = $plan(Flows::project(), Mode::full(), Cut::exactly(2));

    expect($suited instanceof Plan ? $suited->briefing()->suite() : $suited)->toEqual(SuiteName::of('unit'))
        ->and($runner->ran()[0]->suite())->toEqual(SuiteName::of('unit'))
        ->and($runner->ran()[0]->withheld())->toEqual(Withheld::standard()->and(Withheld::of('FAKE_CI_TOKEN')))
        ->and($suited instanceof Plan && $whole instanceof Plan ? $suited->base() : $suited)
        ->not->toEqual($whole instanceof Plan ? $whole->base() : $whole);
});

it('plans a map another job wrote, whatever this job\'s processes held', function () use ($cappedPlan): void {
    expect($cappedPlan(MemoryCap::of(600, MemoryUnit::Megabytes), handedOver: true))->toBeInstanceOf(Plan::class);
});

it('hands each shard the map of its own files', function () use ($plan): void {
    $project = Flows::project();
    $map = CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(3), TestId::of('MoneyTest::adds'))
        ->covered(Path::of('src/Held.php'), Line::of(4), TestId::of('HeldTest::doubles'));
    $plan($project, Mode::full(), Cut::exactly(2), new CoverageAsked(RunnerFake::ofTheFixture(), $map));
    $handed = new Handoff(Flows::adapters($project)->project, HandedMaps::limits());

    expect($handed->read(ShardId::of(1)))
        ->toEqual(CoverageMapFile::decode(CoverageMapFile::encode($map->onlyFor(Paths::of(Path::of('src/Held.php'))), Unplaced::map()), HandedMaps::limits()))
        ->and($handed->read(ShardId::of(2)))
        ->toEqual(CoverageMapFile::decode(
            CoverageMapFile::encode($map->onlyFor(Paths::of(Path::of('src/Money.php'))), Unplaced::map()),
            HandedMaps::limits(),
        ));
});

it('hands each shard the kill history the ledgers learned, which enters no key and no plan digest', function () use (
    $plan,
): void {
    $ranked = Ranking::of(Kills::of(TestId::of('MoneyTest::adds'), 3));
    $history = KillHistory::none()
        ->withMutant(MutantId::hash(Path::of('src/Money.php'), 'Plus', '@@ @@', 0), $ranked)
        ->withFunction(Enclosing::named(Path::of('src/Money.php'), 'add'), $ranked)
        ->withFunction(Enclosing::named(Path::of('src/Held.php'), 'doubles'), $ranked);
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), Ledger::empty()->withKillers($history));
    $project = Flows::project();
    $learned = $plan($project, Mode::full(), Cut::exactly(2), $store);
    $cold = $plan(Flows::project(), Mode::full(), Cut::exactly(2), new ProofStoreFake());
    $handed = new Handoff(Flows::adapters($project)->project, HandedMaps::limits());

    expect($handed->history(ShardId::of(2)))->toEqual($history->onlyIn(Paths::of(Path::of('src/Money.php'))))
        ->and($handed->history(ShardId::of(1)))->toEqual($history->onlyIn(Paths::of(Path::of('src/Held.php'))))
        ->and($learned instanceof Plan ? $learned->digest() : $learned)
        ->toEqual($cold instanceof Plan ? $cold->digest() : $cold)
        ->and($learned instanceof Plan ? $learned->keys() : $learned)
        ->toEqual($cold instanceof Plan ? $cold->keys() : $cold);
});

it('names the coverage map\'s tests once, withholding what every process withholds, outside keys and digest', function () use (
    $plan,
): void {
    $named = NamesAsked::of(RunnerFake::ofTheFixture());
    $unnamed = NamesAsked::refusing(RunnerFake::ofTheFixture(), 'Pest cannot list its tests.');
    $withNames = $plan(Flows::project(), Mode::full(), Cut::exactly(2), $named);
    $withoutNames = $plan(Flows::project(), Mode::full(), Cut::exactly(2), $unnamed);
    $tests = Flows::map()->tests();

    expect($named->asked())->toEqual([[$tests, Withheld::standard()->and(Withheld::of('FAKE_CI_TOKEN'))]])
        ->and($withNames instanceof Plan ? $withNames->names() : $withNames)
        ->toEqual(RunnerFake::ofTheFixture()->names($tests, Withheld::nothing()))
        ->and($withoutNames instanceof Plan ? $withoutNames->names() : $withoutNames)
        ->toEqual(CannotJudge::because('Pest cannot list its tests.'))
        ->and($withNames instanceof Plan ? $withNames->digest() : $withNames)
        ->toEqual($withoutNames instanceof Plan ? $withoutNames->digest() : $withoutNames)
        ->and($withNames instanceof Plan ? $withNames->keys() : $withNames)
        ->toEqual($withoutNames instanceof Plan ? $withoutNames->keys() : $withoutNames);
});

it('says of each shard what its estimate rests on, and that its runner opens on the coverage run\'s tests', function () use (
    $plan,
): void {
    $store = new ProofStoreFake();
    $store->write(
        Scope::branch('main'),
        Ledger::empty()->withTimings(Timings::of(
            Timing::of(Path::of('src/Held.php'), Seconds::of(50.0), 'fake', Moment::at('2026-09-30T10:00:00Z')),
        )),
    );
    $planned = $plan(Flows::project(), Mode::full(), Cut::exactly(1), $store, new CostModelFake(Seconds::of(8.0)));
    $estimates = array_map(
        static fn(Shard $shard): array => [
            $shard->estimate()->part(CostBasis::Learned),
            $shard->estimate()->part(CostBasis::Guessed),
            $shard->estimate()->openingRun(),
        ],
        $planned instanceof Plan ? [...$planned] : [],
    );

    expect($estimates)->toEqual([[Seconds::of(50.0), Seconds::of(8.0), Seconds::of(0.2)]]);
});

it('estimates a unit no shard timed by what it measured of the first run, counting with the engine', function () use (
    $plan,
): void {
    $project = Flows::project();
    // Line 11, which MoneyTest::adds covers in 0.2 s, holds one addition.
    Scratch::write($project, 'src/Money.php', sprintf("<?php\n%sfunction add(\$a, \$b) { return \$a + \$b; }\n", str_repeat("\n", 9)));
    $planned = $plan($project, Mode::full(), Cut::exactly(1), new CostModelFake(Seconds::of(8.0)), Engine::with(new PlusToMinus()));
    $estimates = array_map(
        static fn(Shard $shard): array => [
            $shard->estimate()->part(CostBasis::Learned),
            $shard->estimate()->part(CostBasis::Measured),
            $shard->estimate()->part(CostBasis::Guessed),
        ],
        $planned instanceof Plan ? [...$planned] : [],
    );

    // The fake runner starts a run of no test in 1.5 s and runs one mutant at a time.
    expect($estimates)->toEqual([[Seconds::of(0.0), Seconds::of(1.7), Seconds::of(0.0)]]);
});

it('counts nothing and starts no run of no test where every unit to run is timed', function () use ($plan): void {
    $store = new ProofStoreFake();
    $at = Moment::at('2026-09-30T10:00:00Z');
    $store->write(
        Scope::branch('main'),
        Ledger::empty()->withTimings(Timings::of(
            Timing::of(Path::of('src/Held.php'), Seconds::of(50.0), 'fake', $at),
            Timing::of(Path::of('src/Money.php'), Seconds::of(30.0), 'fake', $at),
        )),
    );
    $runner = ScriptedRunner::fixture();
    $planned = $plan(Flows::project(), Mode::full(), Cut::exactly(1), $runner, $store, Engine::with(new PlusToMinus()));

    expect($planned)->toBeInstanceOf(Plan::class)
        ->and($runner->startedUp())->toBe([])
        ->and($planned instanceof Plan ? [...$planned][0]->estimate()->part(CostBasis::Learned) : $planned)
        ->toEqual(Seconds::of(80.0));
});

it('plans, guessing every unit, where its runs of no test cannot run', function () use ($plan): void {
    $project = Flows::project();
    Scratch::write($project, 'src/Money.php', sprintf("<?php\n%sfunction add(\$a, \$b) { return \$a + \$b; }\n", str_repeat("\n", 9)));
    $runner = ScriptedRunner::fixture()->startingUpIn(CannotJudge::because('Pest could not start'));
    $planned = $plan($project, Mode::full(), Cut::exactly(1), $runner, new CostModelFake(Seconds::of(8.0)), Engine::with(new PlusToMinus()));
    $estimates = array_map(
        static fn(Shard $shard): array => [
            $shard->estimate()->part(CostBasis::Measured),
            $shard->estimate()->part(CostBasis::Guessed),
        ],
        $planned instanceof Plan ? [...$planned] : [],
    );

    expect($planned)->toBeInstanceOf(Plan::class)
        ->and($estimates)->toEqual([[Seconds::of(0.0), Seconds::of(16.0)]]);
});

it('leaves the whole map, in CI and out, where every shard and a later local command read it', function () use (
    $plan,
): void {
    $local = Flows::project();
    $ci = Flows::project();
    $map = CoverageMap::empty()->covered(Path::of('src/Money.php'), Line::of(3), TestId::of('MoneyTest::adds'));
    $plan($local, Mode::full(), Cut::exactly(1), new CoverageAsked(RunnerFake::ofTheFixture(), $map));
    new Planning(
        Flows::adapters($ci, ['CI' => 'true'], new CoverageAsked(RunnerFake::ofTheFixture(), $map)),
        Flows::settings(),
        Flows::setup(),
    )->plan(Mode::full(), CoverageRun::of(WholeSuite::tests(), Workspace::coverage()), Cut::exactly(1), MatrixKind::FirstKiller);

    $at = MeasuredAt::of(Revision::ref(Flows::HEAD), dirty: false);

    $left = static fn(string $project): KeptMap|CannotJudge => CoverageMapFile::kept(
        (string) file_get_contents(sprintf('%s/.mutation-gate/coverage/map.json.gz', $project)),
        MapLimits::standard(),
    );
    $locally = $left($local);

    expect($locally)->toEqual($left($ci))
        ->and($locally instanceof KeptMap ? [$locally->map(), $locally->measuredAt()] : $locally)->toEqual([$map, $at])
        ->and($locally instanceof KeptMap ? array_keys($locally->keys()->written()) : $locally)->toBe(['tests/MoneyTest.php']);
});

it('leaves the whole map saying where it was measured: where another job measured a map it read, and dirty where the tree is', function (): void {
    $read = Flows::project();
    $dirty = Flows::project();
    $elsewhere = MeasuredAt::of(Revision::ref('0123456789abcdef0123456789abcdef01234567'), dirty: false);
    $map = CoverageMap::empty()->covered(Path::of('src/Money.php'), Line::of(3), TestId::of('MoneyTest::adds'));
    Scratch::write($read, 'handed/map.json.gz', CoverageMapFile::encode($map, $elsewhere));
    new Planning(Flows::adapters($read), Flows::settings(), Flows::setup())
        ->plan(Mode::full(), CoverageRead::from(Path::of('handed')), Cut::exactly(1), MatrixKind::FirstKiller);
    new Planning(
        Flows::adapters($dirty, [], RepositoryFake::onMain(Revision::ref(Flows::HEAD))->changed(), new CoverageAsked(RunnerFake::ofTheFixture(), $map)),
        Flows::settings(),
        Flows::setup(),
    )->plan(Mode::full(), CoverageRun::of(WholeSuite::tests(), Workspace::coverage()), Cut::exactly(1), MatrixKind::FirstKiller);

    expect(MeasuredAt::recordedIn((string) file_get_contents(sprintf('%s/.mutation-gate/coverage/map.json.gz', $read)), HandedMaps::limits()))
        ->toEqual($elsewhere)
        ->and(MeasuredAt::recordedIn((string) file_get_contents(sprintf('%s/.mutation-gate/coverage/map.json.gz', $dirty)), HandedMaps::limits()))
        ->toEqual(MeasuredAt::of(Revision::ref(Flows::HEAD), dirty: true));
});

it('cannot plan where it cannot leave the whole map', function () use ($plan): void {
    $project = Flows::project();
    Scratch::write($project, '.mutation-gate/coverage/map.json.gz/blocked', '');

    expect($plan($project, Mode::full(), Cut::exactly(1)))->toEqual(CannotJudge::because(
        sprintf('%s/.mutation-gate/coverage/map.json.gz could not be written.', $project),
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
        Ledger::empty()->withTimings(Timings::of(
            Timing::of(Path::of('src/Held.php'), Seconds::of(50.0), 'fake', Moment::at('2026-09-30T10:00:00Z')),
        )),
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
        ->and($scoped instanceof Plan ? $scoped->considered()->proved() : $scoped)->toEqual(Units::of($money))
        ->and($scoped instanceof Plan ? $scoped->considered()->carried() : $scoped)->toEqual(Units::of($held))
        ->and($scoped instanceof Plan ? $scoped->considered()->changed() : $scoped)
        ->toEqual(Changes::of(Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(2)))))
        ->and($scoped instanceof Plan ? $scoped->base() : $scoped)->toEqual($base)
        ->and($scoped instanceof Plan ? $scoped->keys()->units() : $scoped)
        ->toEqual(Paths::of(Path::of('src/Money.php')));
});

it('proves and carries a run that records every killer only from proofs whose runs recorded every killer, and says so in the plan', function () use (
    $plan,
    $recordingPlan,
    $shards,
    $money,
    $held,
): void {
    $project = Flows::project();
    $full = $plan($project, Mode::full(), Cut::exactly(1));
    $key = $full instanceof Plan ? $full->keys()->keyOf(Path::of('src/Money.php')) : $full;
    $base = $full instanceof Plan ? $full->base() : Digest::of('none');
    $store = static function (MatrixKind $recorded) use ($key, $base): ProofStoreFake {
        $run = Run::of('local', Moment::at('2026-09-30T10:00:00Z'), $base)->recording($recorded);
        $store = new ProofStoreFake();
        $store->write(Scope::branch('main'), Ledger::empty()
            ->withProof(Proof::of($key instanceof Digest ? $key : Digest::of('none'), Path::of('src/Money.php'), Mutants::none(), $run))
            ->withProof(Proof::of(Digest::of('old'), Path::of('src/Held.php'), Mutants::none(), $run)));

        return $store;
    };
    $checkout = static fn(): ChangeSourceFake => new ChangeSourceFake(
        Revision::ref('base'),
        Changes::of(Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(2)))),
        [Revision::workingTree()->name() => Flows::FILES, 'base' => Flows::FILES],
    );
    $fromFirst = $recordingPlan($project, Mode::since('base'), MatrixKind::Full, $store(MatrixKind::FirstKiller), $checkout());
    $fromFull = $recordingPlan($project, Mode::since('base'), MatrixKind::Full, $store(MatrixKind::Full), $checkout());
    $firstFromFull = $recordingPlan($project, Mode::since('base'), MatrixKind::FirstKiller, $store(MatrixKind::Full), $checkout());

    expect($shards($fromFirst))->toEqual([1 => [$money, $held]])
        ->and($fromFirst instanceof Plan ? $fromFirst->considered()->proved() : $fromFirst)->toEqual(Units::none())
        ->and($fromFirst instanceof Plan ? $fromFirst->considered()->carried() : $fromFirst)->toEqual(Units::none())
        ->and($fromFirst instanceof Plan ? $fromFirst->briefing()->matrix() : $fromFirst)->toBe(MatrixKind::Full)
        ->and($fromFull instanceof Plan ? $fromFull->considered()->proved() : $fromFull)->toEqual(Units::of($money))
        ->and($fromFull instanceof Plan ? $fromFull->considered()->carried() : $fromFull)->toEqual(Units::of($held))
        ->and($firstFromFull instanceof Plan ? $firstFromFull->considered()->proved() : $firstFromFull)->toEqual(Units::of($money))
        ->and($firstFromFull instanceof Plan ? $firstFromFull->briefing()->matrix() : $firstFromFull)->toBe(MatrixKind::FirstKiller);
});

it('refuses a run that records every killer under a runner that cannot, and plans one that records first killers', function () use (
    $recordingPlan,
): void {
    $infection = RunnerFake::ofTheFixture()->behaving(RunnerBehaviour::standard()->stoppingAtFirstKiller(NotFull::Infection));

    expect($recordingPlan(Flows::project(), Mode::full(), MatrixKind::Full, $infection))->toEqual(CannotJudge::because(
        'A full kill matrix needs Infection to keep running after a failure, which it cannot.',
    ))->and($recordingPlan(Flows::project(), Mode::full(), MatrixKind::FirstKiller, $infection))->toBeInstanceOf(Plan::class);
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

it('holds the changed lines its coverage map says no test runs', function () use ($plan): void {
    $money = Path::of('src/Money.php');
    $map = CoverageMap::of(
        CoveredLine::of($money, 2, 'MoneyTest::adds'),
        CoveredLine::of($money, 3),
        CoveredLine::of($money, 8),
    );
    $checkout = new ChangeSourceFake(
        Revision::ref('base'),
        Changes::of(Change::modified($money, Lines::of(Line::of(1), Line::of(2), Line::of(3)))),
        [Revision::workingTree()->name() => Flows::FILES, 'base' => Flows::FILES],
    );
    $planned = $plan(Flows::project(), Mode::since('base'), Cut::exactly(1), new CoverageAsked(RunnerFake::ofTheFixture(), $map), $checkout);

    expect($planned instanceof Plan ? $planned->considered()->untested() : $planned)
        ->toEqual(Changes::of(Change::modified($money, Lines::of(Line::of(3)))));
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
    $pullRequest = new CiPlanFake(RunOn::at(Scope::pullRequest(7), Scope::branch('main')));
    $changed = Changes::of(Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(3))));
    $checkout = new ChangeSourceFake(Revision::ref(Flows::MAIN), $changed, [
        Revision::workingTree()->name() => Flows::FILES,
        Flows::MAIN => Flows::FILES,
    ]);

    $planned = $plan(Flows::project(), Mode::full(), Cut::exactly(1), $pullRequest, $checkout);

    expect($planned instanceof Plan ? $planned->considered()->changed() : $planned)->toEqual($changed);
});

it('cannot plan a full pull request run where git cannot tell what changed since the default branch', function () use (
    $plan,
): void {
    $pullRequest = new CiPlanFake(RunOn::at(Scope::pullRequest(7), Scope::branch('main')));

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
    $planned = Planned::from(new Planning(
        Flows::adapters(Flows::project(), [], $runner),
        Flows::settings(Ignores::allowingNativeMarkers()),
        Flows::setup(),
    )->plan(Mode::full(), CoverageRun::of(WholeSuite::tests(), Workspace::coverage()), Cut::exactly(1), MatrixKind::FirstKiller));

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

it('cuts to a target wall time counting the coverage run\'s tests as each shard\'s opening run', function (
    float $opening,
    int $count,
) use ($plan): void {
    $map = Flows::map()->timed(TestId::of('MoneyTest::adds'), Seconds::of($opening));
    $planned = $plan(
        Flows::project(),
        Mode::full(),
        Cut::toTarget(Seconds::of(2.5), Seconds::of(0.0), 20),
        new CoverageAsked(RunnerFake::ofTheFixture(), $map),
    );

    expect($planned instanceof Plan ? count($planned) : $planned)->toBe($count);
})->with([
    'an opening run that leaves room for every unit in one shard' => [0.0, 1],
    'an opening run that leaves room for one unit a shard' => [2.0, 2],
]);

/** The default branch's ledger, proving each of these units with no mutant, so none is at risk from its last result. */
$settled = static function (string ...$units): ProofStoreFake {
    $store = new ProofStoreFake();
    $ledger = Ledger::empty();

    foreach ($units as $unit) {
        $ledger = $ledger->withProof(Proof::of(
            Digest::sha256Of(sprintf('%s before', $unit)),
            Path::of($unit),
            Mutants::none(),
            Run::of('main', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of('main')),
        ));
    }

    $store->write(Scope::branch('main'), $ledger);

    return $store;
};

it('lists each shard\'s units the riskiest first, a unit never mutated before a settled one', function () use (
    $plan,
    $shards,
    $settled,
    $money,
    $held,
): void {
    $planned = $plan(Flows::project(), Mode::full(), Cut::exactly(1), $settled('src/Held.php'));

    expect($shards($planned))->toEqual([1 => [$money, $held]]);
});

it('lists the least risky units the most recently changed first, by what git says', function (
    string $moneyChanged,
    string $heldChanged,
    bool $moneyFirst,
) use ($plan, $shards, $settled, $money, $held): void {
    $checkout = new ChangeSourceFake(
        Revision::ref('base'),
        Changes::none(),
        [Revision::workingTree()->name() => Flows::FILES, 'base' => Flows::FILES, Flows::MAIN => Flows::FILES],
        ['src/Money.php' => $moneyChanged, 'src/Held.php' => $heldChanged],
    );
    $planned = $plan(Flows::project(), Mode::full(), Cut::exactly(1), $settled('src/Held.php', 'src/Money.php'), $checkout);

    expect($shards($planned))->toEqual([1 => $moneyFirst ? [$money, $held] : [$held, $money]]);
})->with([
    'the money file changed last' => ['2026-09-20T10:00:00Z', '2026-09-01T10:00:00Z', true],
    'the held path changed last' => ['2026-09-01T10:00:00Z', '2026-09-20T10:00:00Z', false],
]);

it('lists a unit whose lines the change touched first, before one never mutated', function () use (
    $plan,
    $shards,
    $money,
    $held,
): void {
    $checkout = new ChangeSourceFake(
        Revision::ref('base'),
        Changes::of(Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(2)))),
        [Revision::workingTree()->name() => Flows::FILES, 'base' => Flows::FILES],
    );

    expect($shards($plan(Flows::project(), Mode::since('base'), Cut::exactly(1), new ProofStoreFake(), $checkout)))
        ->toEqual([1 => [$money, $held]]);
});

it('hands the plan the digests of the run\'s inputs, with each unit it runs', function () use ($plan): void {
    $planned = $plan(Flows::project(), Mode::full(), Cut::exactly(1));
    $digests = $planned instanceof Plan ? $planned->digests() : $planned;

    expect($digests)->toBeInstanceOf(Digests::class)
        ->and($digests instanceof Digests ? $digests->sources()->paths() : $digests)
        ->toEqual(Paths::of(Path::of('src/Held.php'), Path::of('src/Money.php')))
        ->and($digests instanceof Digests ? count($digests->tests()) : $digests)->toBeGreaterThan(0)
        ->and($digests instanceof Digests ? $digests->commit() : $digests)->toEqual(Revision::ref(Flows::HEAD));
});

it('takes the digests at no commit where the working tree holds what HEAD does not, or git cannot say', function (
    RepositoryFake $repository,
) use ($plan): void {
    $planned = $plan(Flows::project(), Mode::full(), Cut::exactly(1), $repository);
    $digests = $planned instanceof Plan ? $planned->digests() : $planned;

    expect($digests instanceof Digests ? $digests->commit() : $digests)->toEqual(Uncommitted::tree());
})->with([
    'a changed working tree' => fn(): RepositoryFake => RepositoryFake::onMain(Revision::ref(Flows::HEAD))->changed(),
    'git cannot say' => fn(): RepositoryFake => RepositoryFake::onMain(Revision::ref(Flows::HEAD))->unsure(),
]);

it('takes the digests at no commit where HEAD moved while the plan was made', function () use ($plan): void {
    $moving = new class implements Repository {
        private int $asked = 0;

        public function head(): Revision
        {
            $this->asked++;

            return Revision::ref($this->asked === 1 ? Flows::HEAD : str_repeat('c1', 20));
        }

        public function isClean(): bool
        {
            return true;
        }

        public function branch(): Scope
        {
            return Scope::branch('main');
        }

        public function defaultBranch(): Scope
        {
            return Scope::branch('main');
        }
    };
    $planned = $plan(Flows::project(), Mode::full(), Cut::exactly(1), $moving);
    $digests = $planned instanceof Plan ? $planned->digests() : $planned;

    expect($planned instanceof Plan ? $planned->commit() : $planned)->toEqual(Revision::ref(Flows::HEAD))
        ->and($digests instanceof Digests ? $digests->commit() : $digests)->toEqual(Uncommitted::tree());
});

it('keys the project\'s coverage entries once a plan, for measuring against the kept map and for the map it hands on', function () use (
    $plan,
): void {
    $store = new ProofStoreFake();
    $kept = CoverageMapFile::encode(Flows::map(), MeasuredAt::of(Revision::ref(Flows::HEAD), dirty: false), EntryKeys::none());
    $store->keep(Scope::branch('main'), Companion::Coverage, Contents::of($kept));
    $runner = ScriptedRunner::fixture();

    $planned = $plan(Flows::project(), Mode::full(), Cut::exactly(1), $runner, $store);

    expect($planned)->toBeInstanceOf(Plan::class)
        ->and($runner->identified())->toHaveCount(2);
});

it('briefs a plan measured against the map its own scope keeps as such, and one measured against the default branch\'s as not', function (
    bool $own,
) use ($plan): void {
    $project = Flows::project();
    $files = [...Flows::FILES, 'tests/HeldTest.php' => "<?php\n\nit('doubles', fn () => expect(2)->toBe(2));\n"];
    Scratch::write($project, 'tests/HeldTest.php', $files['tests/HeldTest.php']);
    $checkout = new ChangeSourceFake(Revision::ref(Flows::HEAD), Changes::none(), [
        Revision::workingTree()->name() => $files,
        Flows::HEAD => $files,
    ]);
    $store = new ProofStoreFake();
    $adapters = Flows::adapters($project, [], $store, $checkout);
    $kept = new KeptCoverage($adapters, Flows::settings(), Flows::setup());
    $keys = KeptCoverage::keysOf($kept->entries(Inventory::of($adapters, Flows::settings())), Flows::map());
    $bytes = CoverageMapFile::encode(
        Flows::map(),
        MeasuredAt::of(Revision::ref(Flows::HEAD), dirty: false),
        $keys instanceof EntryKeys ? $keys : EntryKeys::none(),
    );
    $store->keep($own ? Scope::branch('feature') : Scope::branch('main'), Companion::Coverage, Contents::of($bytes));

    $on = new CiPlanFake(RunOn::at(Scope::branch('feature'), Scope::branch('main')));
    $planned = $plan($project, Mode::full(), Cut::exactly(1), $store, $checkout, $on);

    expect($planned instanceof Plan ? $planned->briefing()->isOnOwnScopeCoverage() : $planned)->toBe($own);
})->with(['its own scope\'s map' => [true], 'the default branch\'s map' => [false]]);
