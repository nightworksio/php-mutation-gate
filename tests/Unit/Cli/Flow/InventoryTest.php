<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Inventory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Hold\Holdings;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Port\ChangeSource;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

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
);

/** The inventory of the project, or why there is none. */
$inventory = static fn(object ...$ports): Inventory|CannotJudge => Inventory::of(
    Flows::adapters(Flows::project(), [], ...$ports),
    Configs::flows(),
);

it('finds where the run stands, the trees, every file, the suite, and every unit with its held paths', function () use (
    $listing,
    $inventory,
): void {
    $found = $inventory($listing(Groups::of(Group::named('holds:src/Held.php'))));

    expect($found instanceof Inventory ? $found->standing->head() : $found)->toEqual(Revision::ref('head'))
        ->and($found instanceof Inventory ? $found->trees : $found)->toEqual(Flows::trees())
        ->and($found instanceof Inventory ? $found->files : $found)->toEqual(Flows::checkout()->fingerprints())
        ->and($found instanceof Inventory ? $found->suite->directories() : $found)
        ->toEqual(Paths::of(Path::of('tests')))
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
        Flows::lost(),
        'The commit HEAD is at cannot be read, so the run cannot be tied to one. git is not installed.',
    ],
    'the trees' => [
        new TreeSourceFake(CannotJudge::because('No tree is declared.')),
        'No tree is declared.',
    ],
    'the files' => [
        new class implements ChangeSource {
            public function changesSince(Revision $base): Changes
            {
                return Changes::none();
            }

            public function fingerprints(): CannotTell
            {
                return CannotTell::because('git ls-files failed.');
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
    $found = Inventory::of(Flows::adapters($project, [], $checkout, $listing(Groups::of())), Configs::flows());

    expect($found)->toEqual(CannotJudge::because(sprintf('%s/tests/Nested.php could not be read.', $project)));
});
