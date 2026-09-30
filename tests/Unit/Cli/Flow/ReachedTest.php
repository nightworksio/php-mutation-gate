<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Adapters;
use NightWorksIO\MutationGate\Cli\Flow\Reached;
use NightWorksIO\MutationGate\Cli\Flow\Suite;
use NightWorksIO\MutationGate\Config\Reach;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Reasons;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A workflow that runs the gate, pinned at a commit. */
const REACHED_WORKFLOW = "on: push\njobs:\n  gate:\n    steps:\n      - uses: actions/checkout@%s # v4\n";

$money = Unit::file(Path::of('src/Money.php'));
$held = Unit::held(Path::of('src/Held.php'), Group::named('holds:src/Held.php'));

/**
 * What these changes since `base` reach, where the checkout has these files
 * now and had those at the base, `.github/workflows/gate.yml` runs the gate,
 * `config/**` decides everything, and the fake runner's `phpunit.xml`
 * defines it.
 *
 * @param array<string, string> $now
 * @param array<string, string> $before
 */
function reachedSince(Changes $changes, array $now, array $before, object ...$ports): Reached
{
    $checkout = new ChangeSourceFake(Revision::ref('base'), $changes, [
        Revision::workingTree()->name() => [...Flows::FILES, ...$now],
        'base' => [...Flows::FILES, ...$before],
    ]);
    $project = Flows::project();
    $ci = Flows::ci()->runBy(Paths::of(Path::of('.github/workflows/gate.yml')));
    $adapters = Flows::adapters($project, [], $checkout, $ci, ...$ports);
    $map = CoverageMap::empty()->covered(Path::of('src/Money.php'), Line::of(3), TestId::of('MoneyTest::adds'));

    return Reached::since(
        Revision::ref('base'),
        Flows::trees(),
        $adapters,
        Flows::settings(Reach::everything('config/**')),
        reachedSuite($checkout, $adapters),
        $map,
    );
}

/** The suite the checkout lists, as the project holds it. */
function reachedSuite(ChangeSourceFake $checkout, Adapters $adapters): Suite
{
    $suite = Suite::read(Flows::trees(), $checkout->fingerprints(), $adapters->project);

    return $suite instanceof Suite ? $suite : throw new RuntimeException($suite->why());
}

it('reaches every unit of a full run, for its reason', function () use ($money, $held): void {
    $reached = Reached::everything(Flows::trees(), CannotTell::because('A full run considers every unit.'));

    expect($reached->reach()->reaches($money))->toBeTrue()
        ->and($reached->reach()->reaches($held))->toBeTrue()
        ->and($reached->reach()->reasons())->toEqual(Reasons::of(Reason::that('A full run considers every unit.')))
        ->and($reached->changed())->toEqual(CannotTell::because('A full run considers every unit.'));
});

it('reaches what a source change reaches, and keeps the lines each change added or modified', function () use (
    $money,
    $held,
): void {
    $reached = reachedSince(Changes::of(
        Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(2), Line::of(3))),
        Change::added(Path::of('src/Limit.php'), Lines::of(Line::of(1))),
        Change::renamed(Path::of('src/Old.php'), Path::of('src/New.php'), Lines::none()),
    ), [], []);

    expect($reached->reach()->reaches($money))->toBeTrue()
        ->and($reached->reach()->reaches($held))->toBeFalse()
        ->and($reached->reach()->isEverywhere())->toBeFalse()
        ->and($reached->reach()->changedLines(Path::of('src/Money.php')))->toEqual(Lines::of(Line::of(2), Line::of(3)))
        ->and($reached->changed())->toEqual(Changes::of(
            Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(2), Line::of(3))),
            Change::modified(Path::of('src/Limit.php'), Lines::of(Line::of(1))),
        ));
});

it('reaches the files a changed test runs, as the runner says which tests judge each', function (
    string $test,
    bool $reachesMoney,
) use ($money): void {
    $runner = new RunnerFake(
        Identity::of('fake', Versions::none(), Digest::of('php')),
        Groups::of(),
        CoverageMap::empty(),
        Mutants::none(),
        Paths::of(Path::of('tests/MoneyTest.php')),
        Paths::of(Path::of('phpunit.xml')),
        TestNames::none(),
        Paths::none(),
    );
    $reached = reachedSince(
        Changes::of(Change::modified(Path::of($test), Lines::of(Line::of(4)))),
        [$test => "<?php\n\nit('adds', fn () => 2);\n"],
        [$test => "<?php\n\nit('adds', fn () => 1);\n"],
        $runner,
    );

    expect($reached->reach()->reaches($money))->toBe($reachesMoney)
        ->and($reached->reach()->reaches(Unit::file(Path::of('src/Other.php'))))->toBeFalse()
        ->and($reached->changed())->toEqual(Changes::of(Change::modified(Path::of($test), Lines::of(Line::of(4)))));
})->with([
    'a test that judges it' => ['tests/MoneyTest.php', true],
    'a test that judges nothing' => ['tests/OtherTest.php', false],
]);

it('reaches everything where a file that decides how the gate runs changed', function (string $file) use ($held): void {
    $reached = reachedSince(
        Changes::of(Change::modified(Path::of($file), Lines::of(Line::of(1)))),
        [$file => sprintf(REACHED_WORKFLOW, 'b')],
        [$file => 'on: pull_request'],
    );

    expect($reached->reach()->isEverywhere())->toBeTrue()
        ->and($reached->reach()->reaches($held))->toBeTrue();
})->with([
    'the CI definition that runs it' => ['.github/workflows/gate.yml'],
    'a file reach.everything names' => ['config/app.php'],
    'a file that defines the runner' => ['tests/Pest.php'],
]);

it('reaches nothing where only the commits a workflow is pinned at moved', function () use ($money): void {
    $reached = reachedSince(
        Changes::of(Change::modified(Path::of('.github/workflows/gate.yml'), Lines::of(Line::of(5)))),
        ['.github/workflows/gate.yml' => sprintf(REACHED_WORKFLOW, str_repeat('b', 40))],
        ['.github/workflows/gate.yml' => sprintf(REACHED_WORKFLOW, str_repeat('a', 40))],
    );

    expect($reached->reach()->isEverywhere())->toBeFalse()
        ->and($reached->reach()->reaches($money))->toBeFalse();
});

it('reaches everything where git cannot tell what changed, and says why it keeps no line', function () use ($held): void {
    $checkout = new ChangeSourceFake(
        Revision::ref('elsewhere'),
        Changes::none(),
        [Revision::workingTree()->name() => Flows::FILES],
    );
    $adapters = Flows::adapters(Flows::project(), [], $checkout);
    $reached = Reached::since(
        Revision::ref('base'),
        Flows::trees(),
        $adapters,
        Flows::settings(),
        reachedSuite($checkout, $adapters),
        CoverageMap::empty(),
    );

    expect($reached->reach()->reaches($held))->toBeTrue()
        ->and($reached->changed())->toEqual(CannotTell::because('base is not a revision this repository has.'));
});
