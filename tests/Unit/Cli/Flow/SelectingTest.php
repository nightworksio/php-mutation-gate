<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Handoff;
use NightWorksIO\MutationGate\Cli\Flow\Selected;
use NightWorksIO\MutationGate\Cli\Flow\Selecting;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\Affected;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

/** The commit the map was measured at, as the checkout holds it. */
const SELECTED_AT = '0123456789abcdef0123456789abcdef01234567';

/** The fixture's files, and test support beside its test. */
const SELECTING_FILES = [...Flows::FILES, 'tests/Support/Builds.php' => "<?php\n\nnamespace Tests\\Support;\n\nfinal class Builds {}\n"];

/** What the fixture's suite runs: `tests/MoneyTest.php`'s one test runs Money's line 11. */
$map = static fn(): CoverageMap => CoverageMap::empty()
    ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('MoneyTest::adds'));

/**
 * The project, with a map at `.mutation-gate/coverage` written so, and a
 * checkout where `src/Money.php` changed since the map's commit and, where
 * given, since a ref besides.
 */
$project = static function (string|false $map): string {
    $project = Flows::project();
    Scratch::write($project, 'tests/Support/Builds.php', SELECTING_FILES['tests/Support/Builds.php']);

    if ($map !== false) {
        Scratch::write($project, '.mutation-gate/coverage/map.json.gz', $map);
    }

    return $project;
};

/** The fixture's checkout, where these changed since the map's commit. */
function selectingCheckout(Change ...$changes): ChangeSourceFake
{
    return new ChangeSourceFake(
        Revision::ref(SELECTED_AT),
        Changes::of(...$changes),
        [Revision::workingTree()->name() => SELECTING_FILES, SELECTED_AT => SELECTING_FILES, 'origin/main' => SELECTING_FILES],
    );
}

$checkout = static fn(Change ...$changes): ChangeSourceFake => selectingCheckout(...$changes);

/** What `affected` selects in a project, through a checkout, since a ref or the map's commit, with this runner. */
$select = static fn(
    string $project,
    ChangeSourceFake $checkout,
    string $since = '',
    ScriptedRunner ...$runner,
): Selected|CannotJudge => new Selecting(
    Flows::adapters($project, [], $checkout, $runner === [] ? ScriptedRunner::fixture() : $runner[0]),
    Flows::settings(),
)->selected($since, Path::of('.mutation-gate/coverage'));

/** @return list<string> */
$every = static fn(Selected|CannotJudge $selected): array => $selected instanceof Selected
    ? [...array_column(Affected::listed($selected->tests), 0), ...Affected::texts($selected->tests->everyBecause())]
    : [$selected->why()];

$money = Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(11)));

it('lists the tests the change since the map\'s commit reaches, by the runner\'s own selection', function () use (
    $project,
    $checkout,
    $select,
    $map,
    $money,
): void {
    $at = MeasuredAt::of(Revision::ref(SELECTED_AT), dirty: false);
    $selected = $select($project(CoverageMapFile::encode($map(), $at)), $checkout($money));

    expect($selected instanceof Selected ? array_column(Affected::listed($selected->tests), 0) : $selected)
        ->toBe(['tests/DrainSpec.php', 'tests/MoneySpec.php'])
        ->and($selected instanceof Selected ? [$selected->base, $selected->map] : $selected)->toEqual([NotGiven::value(), $at])
        ->and($selected instanceof Selected && $selected->tests->isEvery())->toBeFalse();
});

it('reads the change since a ref besides, a path changed since both taken once', function () use (
    $project,
    $checkout,
    $select,
    $map,
    $money,
): void {
    $at = MeasuredAt::of(Revision::ref(SELECTED_AT), dirty: false);
    $selected = $select(
        $project(CoverageMapFile::encode($map(), $at)),
        $checkout($money)->alsoFrom(Revision::ref('origin/main')),
        'origin/main',
    );

    expect($selected instanceof Selected ? $selected->base : $selected)->toEqual(Revision::ref('origin/main'))
        ->and($selected instanceof Selected ? array_column(Affected::listed($selected->tests), 2) : $selected)->toBe([
            ['`src/Money.php` changed, and the runner selects this file to judge it.'],
            ['`src/Money.php` changed, and the runner selects this file to judge it.'],
        ]);
});

it('lists every test, saying why, where the map cannot say what the change reaches', function (
    string|false $map,
    ChangeSourceFake $checkout,
    string $since,
    string $why,
) use ($project, $select, $every): void {
    $listed = $every($select($project($map), $checkout, $since));

    expect($listed)->toBe(['tests/MoneyTest.php', $why]);
})->with([
    'no map' => [false, selectingCheckout(), '', 'No coverage map is at .mutation-gate/coverage/map.json.gz, and the default branch keeps none, so every test is listed.'],
    'a map that is not one' => [
        'not a map',
        selectingCheckout(),
        '',
        'The coverage map is not one this gate writes, so no line of it can be read. So every test is listed.',
    ],
    'a map that does not say where it was measured' => [
        CoverageMapFile::encode(CoverageMap::empty(), Unplaced::map()),
        selectingCheckout(),
        '',
        'The coverage map does not say where it was measured, so every test is listed.',
    ],
    'a map measured in a dirty tree' => [
        CoverageMapFile::encode(CoverageMap::empty(), MeasuredAt::of(Revision::ref(SELECTED_AT), dirty: true)),
        selectingCheckout(),
        '',
        'The coverage map was measured in a dirty working tree, so every test is listed.',
    ],
    'a map whose commit the checkout does not have' => [
        CoverageMapFile::encode(CoverageMap::empty(), MeasuredAt::of(Revision::ref('89abcdef0123456789abcdef0123456789abcdef'), dirty: false)),
        selectingCheckout(),
        '',
        'What changed since 89abcdef0123456789abcdef0123456789abcdef, where the coverage map was measured, cannot be told. 89abcdef0123456789abcdef0123456789abcdef is not a revision this repository has. So every test is listed.',
    ],
    'no commit of the scope passed' => [
        false,
        selectingCheckout(),
        'last-passed',
        'No commit of this scope has passed yet, so every test is listed.',
    ],
]);

it('cannot tell what changed since a ref git does not have', function () use ($project, $select, $map): void {
    $at = MeasuredAt::of(Revision::ref(SELECTED_AT), dirty: false);

    expect($select($project(CoverageMapFile::encode($map(), $at)), selectingCheckout(), 'gone'))
        ->toEqual(CannotJudge::because('Git cannot tell what changed since gone. gone is not a revision this repository has. Run the whole suite.'));
});

it('lists every test where the runner cannot say which tests judge a changed file', function () use (
    $project,
    $select,
    $every,
    $map,
    $money,
): void {
    $at = MeasuredAt::of(Revision::ref(SELECTED_AT), dirty: false);
    $runner = ScriptedRunner::fixture()->refusingJudges('The runner cannot read its suite.');

    expect($every($select($project(CoverageMapFile::encode($map(), $at)), selectingCheckout($money), '', $runner)))->toBe([
        'tests/MoneyTest.php',
        'The runner cannot say which tests judge `src/Money.php`. The runner cannot read its suite. So every test is listed.',
    ]);
});

it('cannot select where the units cannot be found', function () use ($project, $select): void {
    $runner = ScriptedRunner::fixture()->unlisted('The runner cannot list its groups.');

    expect($select($project(map: false), selectingCheckout(), '', $runner))->toBeInstanceOf(CannotJudge::class);
});

it('reads the map the default branch keeps where none is in the directory, and says why one it cannot read is no map', function (
    string $kept,
    array $listed,
) use ($project, $checkout, $map, $money): void {
    $store = new ProofStoreFake();
    $store->keep(Scope::branch('main'), Companion::Coverage, Contents::of(sprintf($kept, CoverageMapFile::encode(
        $map(),
        MeasuredAt::of(Revision::ref(SELECTED_AT), dirty: false),
    ))));
    $selected = new Selecting(
        Flows::adapters($project(map: false), [], $checkout($money), ScriptedRunner::fixture(), $store),
        Flows::settings(),
    )->selected('', Path::of('.mutation-gate/coverage'));

    expect($selected instanceof Selected
        ? [...array_column(Affected::listed($selected->tests), 0), ...Affected::texts($selected->tests->everyBecause())]
        : $selected)->toBe($listed);
})->with([
    'a kept map' => ['%s', ['tests/DrainSpec.php', 'tests/MoneySpec.php']],
    'a kept map that is no map' => [
        'not a map',
        ['tests/MoneyTest.php', 'The coverage map is not a whole gzip stream. So every test is listed.'],
    ],
]);

it('lists every test, saying why, where the map is past the compressed limit', function () use ($project, $select, $every): void {
    $at = $project(map: false);
    Scratch::sized($at, '.mutation-gate/coverage/map.json.gz', Handoff::limits()->readable() + 1);

    expect($every($select($at, selectingCheckout())))->toBe(['tests/MoneyTest.php', sprintf(
        '%s/.mutation-gate/coverage/map.json.gz is past %d bytes, so it is not read. So every test is listed.',
        $at,
        Handoff::limits()->packed(),
    )]);
});
