<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Inventory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\JudgedCommit;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Hold\Addition;
use NightWorksIO\MutationGate\Core\Hold\Holdings;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\RunnerBehaviour;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\SuiteDirectory;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Port\ChangeSource;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\RepositoryFake;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\HoldingSuites;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

/** A runner that lists these groups, or cannot. */
$listing = static fn(Groups|CannotJudge $groups): RunnerFake => new RunnerFake(
    Identity::of('fake', Versions::none(), Digest::of('php')),
    $groups,
    CoverageMap::empty(),
    Mutants::none(),
    Paths::none(),
    Paths::none(),
    TestNames::none(),
    Paths::none(),
);

/** The inventory of the project, or why there is none. */
$inventory = static fn(object ...$ports): Inventory|CannotJudge => Inventory::of(
    Flows::adapters(Flows::project(), [], ...$ports),
    Flows::settings(),
);

it('finds where the run stands, the trees, every file, the suite, and every unit with its held paths', function () use (
    $listing,
    $inventory,
): void {
    $found = $inventory($listing(Groups::of(Group::named('holds:src/Held.php'))));

    expect($found instanceof Inventory ? $found->standing->head() : $found)->toEqual(Revision::ref(Flows::HEAD))
        ->and($found instanceof Inventory ? $found->trees : $found)->toEqual(Flows::trees())
        ->and($found instanceof Inventory ? $found->files : $found)->toEqual(Flows::checkout()->fingerprints())
        ->and($found instanceof Inventory ? $found->suite->directories() : $found)
        ->toEqual([SuiteDirectory::of(Path::of('tests'), '')])
        ->and($found instanceof Inventory ? $found->units : $found)->toEqual(Units::of(
            Unit::held(Path::of('src/Held.php'), Group::named('holds:src/Held.php')),
            Unit::file(Path::of('src/Money.php')),
        ));
});

it('cannot find the units where anything it asks cannot answer', function (
    object $port,
    string $why,
) use ($inventory): void {
    expect($inventory($port))->toEqual(CannotJudge::because($why));
})->with([
    'where the run stands' => [
        fn(): RepositoryFake => Flows::lost(),
        'The commit HEAD is at cannot be read, so the run cannot be tied to one. git is not installed.',
    ],
    'the trees' => [
        fn(): TreeSourceFake => new TreeSourceFake(CannotJudge::because('No tree is declared.')),
        'No tree is declared.',
    ],
    'the files' => [
        fn(): ChangeSource => new class implements ChangeSource {
            public function changesSince(Revision $base): Changes
            {
                return Changes::none();
            }

            public function changesFrom(Revision $commit): Changes
            {
                return Changes::none();
            }

            public function judged(Revision $commit): CannotTell
            {
                return CannotTell::because('git cat-file failed.');
            }

            public function readable(JudgedCommit $judged): CannotTell
            {
                return CannotTell::because('git merge-tree failed.');
            }

            public function fingerprints(): CannotTell
            {
                return CannotTell::because('git ls-files failed.');
            }

            public function unstaged(): Paths
            {
                return Paths::none();
            }

            public function lastChanged(Paths $paths): CannotTell
            {
                return CannotTell::because('git log failed.');
            }

            public function fileAt(Path $path, Revision $revision): Missing
            {
                return Missing::at($path);
            }

            public function filesAt(Paths $paths, Revision $revision): CannotTell
            {
                return CannotTell::because('git cat-file failed.');
            }
        },
        'The files of the repository cannot be listed, so no unit can be found. git ls-files failed.',
    ],
]);

it('cannot find the units where the runner cannot list its groups', function () use ($listing, $inventory): void {
    expect($inventory($listing(CannotJudge::because('The runner cannot list its groups.'))))
        ->toEqual(CannotJudge::because('The runner cannot list its groups.'));
});

it('cannot find the units where a group holds a path no tree has', function () use ($listing, $inventory): void {
    $groups = Groups::of(Group::named('holds:src/Nowhere.php'));
    $refused = Holdings::inGroups($groups)->units(Flows::trees(), Flows::checkout()->fingerprints());

    expect($refused)->toBeInstanceOf(CannotJudge::class)
        ->and($inventory($listing($groups)))->toEqual($refused);
});

it('cannot find the units where a test file cannot be read', function () use ($listing): void {
    $project = Flows::project();
    Scratch::write($project, 'tests/Nested.php/Inside.php', "<?php\n");
    $files = [...Flows::FILES, 'tests/Nested.php' => "<?php\n"];
    $checkout = new ChangeSourceFake(
        Revision::ref('base'),
        Changes::none(),
        [Revision::workingTree()->name() => $files],
    );
    $found = Inventory::of(Flows::adapters($project, [], $checkout, $listing(Groups::of())), Flows::settings());

    expect($found)->toEqual(CannotJudge::because(sprintf('%s/tests/Nested.php could not be read.', $project)));
});

/** Under Pest, the inventory of a project with this test file, listing these groups. */
$underPest = static function (
    Groups $groups,
    string $test,
    string $file = 'tests/HeldTest.php',
): Inventory|CannotJudge {
    $project = Flows::project();
    $files = [...Flows::FILES, $file => $test];
    Scratch::write($project, $file, $test);
    $pest = new RunnerFake(
        Identity::of('pest', Versions::none(), Digest::of('php')),
        $groups,
        CoverageMap::empty(),
        Mutants::none(),
        Paths::none(),
        Paths::of(Path::of('tests/Pest.php')),
        TestNames::none(),
        Paths::none(),
    )->behaving(RunnerBehaviour::standard()->holdingAsLoaded());
    $checkout = new ChangeSourceFake(Revision::ref('base'), Changes::none(), [
        Revision::workingTree()->name() => $files,
    ]);

    return Inventory::of(Flows::adapters($project, [], $checkout, $pest), Flows::settings());
};

$heldTest = <<<'PHP'
    <?php

    use NightWorksIO\MutationGate\Attribute\Holds;

    it('doubles', #[Holds('src/Held.php')] function () {
        expect(2)->toBe(2);
    });

    PHP;

it('holds under Pest what the groups Pest lists hold, once each #[Holds] is among them', function () use (
    $underPest,
    $heldTest,
): void {
    $found = $underPest(Groups::of(Group::named('holds:src/Held.php')), $heldTest);

    expect($found instanceof Inventory ? [...$found->units] : $found)->toEqual([
        Unit::held(Path::of('src/Held.php'), Group::named('holds:src/Held.php')),
        Unit::file(Path::of('src/Money.php')),
    ]);
});

it('cannot find the units under Pest where a #[Holds] is not among the groups Pest lists', function () use (
    $underPest,
    $heldTest,
): void {
    $found = $underPest(Groups::of(), $heldTest);

    expect($found instanceof CannotJudge ? $found->why() : $found)
        ->toStartWith(sprintf(
            '%s %s',
            "tests/HeldTest.php:5: #[Holds('src/Held.php')] is written here,",
            'but Pest lists no group holds:src/Held.php.',
        ));
});

it('cannot find the units under Pest where a #[Holds] stands where no group can follow from it', function () use (
    $underPest,
): void {
    $bootstrap = <<<'PHP'
        <?php

        use NightWorksIO\MutationGate\Attribute\Holds;

        it('doubles', #[Holds('src/Held.php')] function () {
            expect(2)->toBe(2);
        });

        PHP;
    $found = $underPest(Groups::of(Group::named('holds:src/Held.php')), $bootstrap, 'tests/Pest.php');

    expect($found instanceof CannotJudge ? $found->why() : $found)
        ->toStartWith("tests/Pest.php:5: #[Holds('src/Held.php')] stands in tests/Pest.php");
});

it('lists the runner\'s groups withholding what every process running the project\'s code does', function (): void {
    $runner = ScriptedRunner::fixture();
    $adapters = Flows::adapters(Flows::project(), [], $runner);

    Inventory::of($adapters, Flows::settings());

    expect($runner->listings())->toEqual([$adapters->withheld]);
});

/**
 * The inventory of the holding-suites project with these files besides, its
 * runner behaving so and listing these tests over Unit and these over Process.
 *
 * @param array<string, string> $more
 */
function inventoryHeldFromProcess(
    TestListing $unit,
    TestListing $process,
    RunnerBehaviour|NotGiven $behaviour = new NotGiven(),
    array $more = [],
): Inventory|CannotJudge {
    $runner = HoldingSuites::runner($unit, $process, CoverageMap::empty())
        ->behaving($behaviour instanceof RunnerBehaviour ? $behaviour : RunnerBehaviour::standard())
        ->definedBy(Paths::of(Path::of('tests/Arch/Bootstrap.php')));

    return Inventory::of(
        HoldingSuites::adapters(HoldingSuites::project($more), HoldingSuites::checkout($more), $runner),
        Flows::settings(),
    );
}

$heldInProcess = static fn(): TestListing => TestListing::of(TestIds::of(TestId::of('P\Tests\Process\HeldTest::starts')))
    ->grouping(Group::named('holds:src/Held.php'), TestIds::of(TestId::of('P\Tests\Process\HeldTest::starts')));

it('leaves a path held only from a holding suite a unit of its tree, its hold adding judges', function () use ($heldInProcess): void {
    $found = inventoryHeldFromProcess(TestListing::of(TestIds::of(TestId::of('P\Tests\Unit\MoneyTest::adds'))), $heldInProcess());

    expect($found instanceof Inventory ? [...$found->units] : $found)->toEqual([
        Unit::file(Path::of('src/Money.php')),
        Unit::file(Path::of('src/Held.php')),
    ])
        ->and($found instanceof Inventory ? [...$found->additions] : $found)->toEqual([
            Addition::of(Path::of('src/Held.php'), TestIds::of(TestId::of('P\Tests\Process\HeldTest::starts')), 'holds:src/Held.php'),
        ]);
});

it('holds a path some judging suite\'s tests hold as a unit of its own, judged by them and the holding suites\' alone', function () use ($heldInProcess): void {
    $unit = TestListing::of(TestIds::of(TestId::of('P\Tests\Unit\HeldTest::doubles')))
        ->grouping(Group::named('holds:src/Held.php'), TestIds::of(TestId::of('P\Tests\Unit\HeldTest::doubles')));
    $found = inventoryHeldFromProcess($unit, $heldInProcess());

    expect($found instanceof Inventory ? [...$found->units] : $found)->toEqual([
        Unit::held(Path::of('src/Held.php'), Group::named('holds:src/Held.php')),
        Unit::file(Path::of('src/Money.php')),
    ])
        ->and($found instanceof Inventory ? count($found->additions) : $found)->toBe(0);
});

it('cannot find the units where the runner opens each shard on its own coverage run and a holding suite holds a path', function () use ($heldInProcess): void {
    expect(inventoryHeldFromProcess(TestListing::none(), $heldInProcess(), RunnerBehaviour::standard()->openingEachShard()))
        ->toEqual(CannotJudge::because(<<<'SAID'
            holds:src/Held.php holds src/Held.php only from the holding suites, which adds their tests to the judges of its lines
            through the coverage map the gate hands the runner. This runner opens each shard on a coverage
            run of its own, as Pest does without pest.patch, so it cannot. Turn on pest.patch, or hold the
            path from a suite tests.suites lists.
            SAID))
        ->and(inventoryHeldFromProcess(TestListing::none(), TestListing::none(), RunnerBehaviour::standard()->openingEachShard()))
        ->toBeInstanceOf(Inventory::class);
});

it('cannot find the units where the runner cannot list the holding suites\' tests', function (): void {
    $runner = HoldingSuites::runner(TestListing::none(), TestListing::none(), CoverageMap::empty())
        ->listingIn(Suites::listed('Process'), CannotJudge::because('Pest cannot list Process.'));

    expect(Inventory::of(HoldingSuites::adapters(HoldingSuites::project(), HoldingSuites::checkout(), $runner), Flows::settings()))
        ->toEqual(CannotJudge::because('Pest cannot list Process.'));
});

it('reads no #[Holds] from a suite neither list names, which judges nothing', function (): void {
    $arch = <<<'PHP_WRAP'
    <?php
    
    use NightWorksIO\MutationGate\Attribute\Holds;
    
    #[Holds('src/Nowhere.php')]
    final class RulesTest extends \PHPUnit\Framework\TestCase
    {
    }
    
    PHP_WRAP;
    $found = inventoryHeldFromProcess(TestListing::none(), TestListing::none(), more: ['tests/Arch/RulesTest.php' => $arch]);

    expect($found)->toBeInstanceOf(Inventory::class)
        ->and($found instanceof Inventory ? count($found->additions) : $found)->toBe(0);
});

it('reads under Pest the #[Holds] of the files Pest loads first, whatever suite runs, and none of a suite no list names', function () use ($heldInProcess): void {
    $held = <<<'PHP'
        <?php

        use NightWorksIO\MutationGate\Attribute\Holds;

        it('doubles', #[Holds('src/Held.php')] function () {
            expect(2)->toBe(2);
        });

        PHP;
    $pest = RunnerBehaviour::standard()->holdingAsLoaded();
    $arch = inventoryHeldFromProcess(TestListing::none(), $heldInProcess(), $pest, ['tests/Arch/HeldTest.php' => str_replace('src/Held.php', 'src/Nowhere.php', $held)]);
    $bootstrap = inventoryHeldFromProcess(TestListing::none(), $heldInProcess(), $pest, ['tests/Arch/Bootstrap.php' => $held]);

    expect($arch)->toBeInstanceOf(Inventory::class)
        ->and($bootstrap instanceof CannotJudge ? $bootstrap->why() : $bootstrap)
        ->toStartWith("tests/Arch/Bootstrap.php:5: #[Holds('src/Held.php')] stands in tests/Arch/Bootstrap.php");
});

it('lists the holding suites\' tests only once the judging suites\' are listed', function (): void {
    $runner = ScriptedRunner::fixture()->listingGroups(
        HoldingSuites::runner(TestListing::none(), TestListing::none(), CoverageMap::empty())
            ->listingIn(Suites::listed('Unit'), CannotJudge::because('Pest cannot list Unit.')),
    );

    expect(Inventory::of(HoldingSuites::adapters(HoldingSuites::project(), HoldingSuites::checkout(), $runner), Flows::settings()))
        ->toEqual(CannotJudge::because('Pest cannot list Unit.'))
        ->and($runner->listedSuites())->toEqual([Suites::listed('Unit')]);
});
