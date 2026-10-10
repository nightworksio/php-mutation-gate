<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Inventory;
use NightWorksIO\MutationGate\Cli\Flow\KeptCoverage;
use NightWorksIO\MutationGate\Cli\Flow\Mode;
use NightWorksIO\MutationGate\Cli\Flow\PlanMade;
use NightWorksIO\MutationGate\Cli\Flow\Planning;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\Coverage\EntryKeys;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Cut;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;
use NightWorksIO\MutationGate\Tests\Support\CoverageAsked;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\HandedMaps;
use NightWorksIO\MutationGate\Tests\Support\HoldingSuites;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A full plan of the holding-suites project, its runner measuring this map, src/Held.php held from Process. */
$plan = static function (string $project, CoverageMap $map): Plan|CannotJudge {
    $process = TestListing::of(TestIds::of(TestId::of(HoldingSuites::STARTS)))
        ->grouping(Group::named('holds:src/Held.php'), TestIds::of(TestId::of(HoldingSuites::STARTS)));
    $runner = new CoverageAsked(HoldingSuites::runner(TestListing::none(), $process, $map), $map);

    return Planned::from(new Planning(HoldingSuites::adapters($project, HoldingSuites::checkout(), $runner), Flows::settings(), Flows::setup())
        ->plan(Mode::full(), KeptCoverage::built(), Cut::exactly(1), MatrixKind::FirstKiller));
};

it('hands its shards the map the listed suites may judge: a holding suite\'s test on what it holds, no unlisted suite\'s test', function () use ($plan): void {
    $project = HoldingSuites::project();
    $planned = $plan($project, CoverageMap::of(
        CoveredLine::of(Path::of('src/Money.php'), 4, HoldingSuites::ADDS, HoldingSuites::STARTS, HoldingSuites::RULES),
        CoveredLine::of(Path::of('src/Held.php'), 4, HoldingSuites::STARTS, HoldingSuites::RULES),
    ));
    $handed = CoverageMapFile::decode((string) file_get_contents(sprintf('%s/.mutation-gate/coverage/map.json.gz', $project)), HandedMaps::limits());

    expect($planned)->toBeInstanceOf(Plan::class)
        ->and($handed instanceof CoverageMap ? [...$handed->lines()] : $handed)->toEqual([
            CoveredLine::of(Path::of('src/Money.php'), 4, HoldingSuites::ADDS),
            CoveredLine::of(Path::of('src/Held.php'), 4, HoldingSuites::STARTS),
        ]);
});

it('cannot plan where a hold from the holding suites runs no line of what it holds', function () use ($plan): void {
    $planned = $plan(HoldingSuites::project(), CoverageMap::of(
        CoveredLine::of(Path::of('src/Money.php'), 4, HoldingSuites::ADDS, HoldingSuites::STARTS),
    ));

    expect($planned instanceof CannotJudge ? $planned->why() : $planned)
        ->toStartWith('holds:src/Held.php in the holding suites runs no line of src/Held.php');
});

it('measures again, against the map kept before, only the moved test files of the suites the config lists', function (): void {
    $map = CoverageMap::of(
        CoveredLine::of(Path::of('src/Money.php'), 4, HoldingSuites::ADDS),
        CoveredLine::of(Path::of('src/Held.php'), 4, HoldingSuites::STARTS),
    );
    $process = TestListing::of(TestIds::of(TestId::of(HoldingSuites::STARTS)))
        ->grouping(Group::named('holds:src/Held.php'), TestIds::of(TestId::of(HoldingSuites::STARTS)));
    $runner = new CoverageAsked(HoldingSuites::runner(TestListing::none(), $process, $map), $map);
    $project = HoldingSuites::project();
    $adapters = HoldingSuites::adapters($project, HoldingSuites::checkout(), $runner);
    $kept = new KeptCoverage($adapters, Flows::settings(), Flows::setup());
    $inventory = Inventory::of($adapters, Flows::settings());
    $keys = $inventory instanceof Inventory ? KeptCoverage::keysOf($kept->entries($inventory), $map) : $inventory;
    Scratch::write($project, '.mutation-gate/coverage/map.json.gz', CoverageMapFile::encode($map, MeasuredAt::of(Revision::ref(Flows::HEAD), dirty: false), $keys instanceof EntryKeys ? $keys : NotGiven::value()));
    $moved = ['tests/Arch/RulesTest.php' => "<?php\n\n// changed\n", 'tests/Process/HeldTest.php' => sprintf("%s// changed\n", HoldingSuites::FILES['tests/Process/HeldTest.php'])];
    Scratch::write($project, 'tests/Arch/RulesTest.php', $moved['tests/Arch/RulesTest.php']);
    Scratch::write($project, 'tests/Process/HeldTest.php', $moved['tests/Process/HeldTest.php']);
    $made = new Planning(HoldingSuites::adapters($project, HoldingSuites::checkout($moved), $runner), Flows::settings(), Flows::setup())
        ->plan(Mode::full(), KeptCoverage::built(), Cut::exactly(1), MatrixKind::FirstKiller, ownMap: true);

    expect($made instanceof PlanMade ? $made->coverage() : $made)
        ->toBe("Coverage: measured 1 of 3 test files again; kept the rest from the last round's map.");
});
