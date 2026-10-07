<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\Config\Detected;
use NightWorksIO\MutationGate\Cli\Config\Effective;
use NightWorksIO\MutationGate\Cli\Config\Registered;
use NightWorksIO\MutationGate\Cli\Flow\Adapters;
use NightWorksIO\MutationGate\Cli\Flow\ChangeBase;
use NightWorksIO\MutationGate\Cli\Flow\DecidingConfig;
use NightWorksIO\MutationGate\Cli\Flow\Reached;
use NightWorksIO\MutationGate\Cli\Flow\Suite;
use NightWorksIO\MutationGate\Config\Reach;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\ConfigReads;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Reasons;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\JudgedCommits;
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
 * now, on disk too, and had those at the base, `.github/workflows/gate.yml` runs the gate,
 * `config/**` decides everything, and the fake runner's `phpunit.xml`
 * defines it.
 *
 * @param array<string, string> $now
 * @param array<string, string> $before
 */
function reachedSince(Changes $changes, array $now, array $before, object ...$ports): Reached
{
    return reachedReading(DecidingConfig::unread(...), ConfigReads::none(), $changes, $now, $before, ...$ports);
}

/**
 * The same, where the run reads its config file as this reads it, given the
 * project, and the config file reads these files beside itself.
 *
 * @param Closure(string): DecidingConfig $config
 * @param array<string, string>           $now
 * @param array<string, string>           $before
 */
function reachedReading(
    Closure $config,
    ConfigReads $reads,
    Changes $changes,
    array $now,
    array $before,
    object ...$ports,
): Reached {
    $checkout = new ChangeSourceFake(Revision::ref('base'), $changes, [
        Revision::workingTree()->name() => [...Flows::FILES, ...$now],
        'base' => [...Flows::FILES, ...$before],
    ]);
    $project = Flows::project();

    foreach ($now as $path => $contents) {
        Scratch::write($project, $path, $contents);
    }

    $ci = Flows::ci()->runBy(Paths::of(Path::of('.github/workflows/gate.yml')));
    $adapters = Flows::adapters($project, [], $checkout, $ci, $reads, ...$ports);
    $map = CoverageMap::empty()->covered(Path::of('src/Money.php'), Line::of(3), TestId::of('MoneyTest::adds'));

    return Reached::since(
        ChangeBase::since(Revision::ref('base')),
        Flows::trees(),
        $adapters,
        Flows::settings(Reach::everything('config/**')),
        reachedSuite($checkout, $adapters),
        $map,
        $config($project),
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

it('tells a changed test by the suffix the PHPUnit config gives the directory it is in', function () use (
    $money,
): void {
    $config = <<<'XML'
        <phpunit>
            <testsuites>
                <testsuite name="Specs"><directory suffix="Spec.php">spec</directory></testsuite>
            </testsuites>
        </phpunit>
        XML;
    $runner = new RunnerFake(
        Identity::of('fake', Versions::none(), Digest::of('php')),
        Groups::of(),
        CoverageMap::empty(),
        Mutants::none(),
        Paths::of(Path::of('spec/MoneySpec.php')),
        Paths::of(Path::of('phpunit.xml')),
        TestNames::none(),
        Paths::none(),
    );
    $reached = reachedSince(
        Changes::of(Change::modified(Path::of('spec/MoneySpec.php'), Lines::of(Line::of(4)))),
        ['phpunit.xml' => $config, 'spec/MoneySpec.php' => "<?php\n\nit('adds', fn () => 2);\n"],
        ['phpunit.xml' => $config, 'spec/MoneySpec.php' => "<?php\n\nit('adds', fn () => 1);\n"],
        $runner,
    );

    expect($reached->reach()->reaches($money))->toBeTrue()
        ->and($reached->reach()->isEverywhere())->toBeFalse();
});

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

it('reaches everything where the commit a workflow pins an action at moved', function () use ($money): void {
    $reached = reachedSince(
        Changes::of(Change::modified(Path::of('.github/workflows/gate.yml'), Lines::of(Line::of(5)))),
        ['.github/workflows/gate.yml' => sprintf(REACHED_WORKFLOW, str_repeat('b', 40))],
        ['.github/workflows/gate.yml' => sprintf(REACHED_WORKFLOW, str_repeat('a', 40))],
    );

    expect($reached->reach()->isEverywhere())->toBeTrue()
        ->and($reached->reach()->reaches($money))->toBeTrue();
});

it('reaches nothing where only a workflow\'s comment lines changed', function () use ($money): void {
    $reached = reachedSince(
        Changes::of(Change::modified(Path::of('.github/workflows/gate.yml'), Lines::of(Line::of(5)))),
        ['.github/workflows/gate.yml' => sprintf("# The gate.\n%s", sprintf(REACHED_WORKFLOW, str_repeat('a', 40)))],
        ['.github/workflows/gate.yml' => sprintf(REACHED_WORKFLOW, str_repeat('a', 40))],
    );

    expect($reached->reach()->isEverywhere())->toBeFalse()
        ->and($reached->reach()->reaches($money))->toBeFalse();
});

it('reaches nothing where the config file the run reads changed no setting that affects results, and everything where it did', function (string $before, bool $everywhere) use ($money): void {
    $config = static fn(string $project): DecidingConfig => DecidingConfig::read(
        new Effective(
            $project,
            Registered::config(new Extensions(Origin::of('nightworksio/mutation-gate')), static fn(): bool => true),
            new Detected(Directory::at($project), Directory::at(sprintf('%s/vendor', $project))),
            new DateTimeImmutable(Configs::NOW),
        ),
        CommandLine::nothing(),
        $project,
        Path::of('mutation-gate.json'),
    );
    $reached = reachedReading(
        $config,
        ConfigReads::none(),
        Changes::of(Change::modified(Path::of('mutation-gate.json'), Lines::of(Line::of(1)))),
        ['mutation-gate.json' => '{"runner": "pest", "trees": [{"path": "src", "floor": 90}]}'],
        ['mutation-gate.json' => $before],
    );

    expect($reached->reach()->isEverywhere())->toBe($everywhere)
        ->and($reached->reach()->reaches($money))->toBe($everywhere);
})->with([
    'a floor' => ['{"runner": "pest", "trees": [{"path": "src", "floor": 80}]}', false],
    'the trees' => ['{"runner": "pest", "trees": [{"path": "lib"}]}', true],
]);

it('reaches everything where a file the config reads beside itself changed, was added, deleted or renamed', function (Change $change) use ($held): void {
    $reached = reachedReading(
        DecidingConfig::unread(...),
        ConfigReads::named(Path::of('settings/shared.php')),
        Changes::of($change),
        ['settings/shared.php' => '<?php', 'settings/moved.php' => '<?php'],
        ['settings/shared.php' => '<?php // before', 'settings/new.php' => '<?php'],
    );

    expect($reached->reach()->isEverywhere())->toBeTrue()
        ->and($reached->reach()->reaches($held))->toBeTrue()
        ->and(array_map(static fn(Reason $reason): string => $reason->text(), [...$reached->reach()->reasons()]))
        ->toBe(['`settings/shared.php` decides how the gate runs, so every unit is reached.']);
})->with([
    'edited' => [Change::modified(Path::of('settings/shared.php'), Lines::of(Line::of(1)))],
    'added' => [Change::added(Path::of('settings/shared.php'), Lines::of(Line::of(1)))],
    'deleted' => [Change::deleted(Path::of('settings/shared.php'))],
    'renamed away' => [Change::renamed(Path::of('settings/shared.php'), Path::of('settings/moved.php'), Lines::of())],
    'renamed into its place' => [Change::renamed(Path::of('settings/new.php'), Path::of('settings/shared.php'), Lines::of())],
]);

it('reaches everything on every change where the config reads a file it cannot name, saying why, and nothing with no change', function () use ($money): void {
    $why = 'mutation-gate.php reads what the gate cannot name without running it (`file_get_contents()` on line 7), so every change reaches everything.';
    $reached = reachedReading(
        DecidingConfig::unread(...),
        ConfigReads::unnamed($why),
        Changes::of(Change::modified(Path::of('README.md'), Lines::of(Line::of(1)))),
        ['README.md' => '# Now'],
        ['README.md' => '# Then'],
    );
    $unchanged = reachedReading(DecidingConfig::unread(...), ConfigReads::unnamed($why), Changes::none(), [], []);

    expect($reached->reach()->isEverywhere())->toBeTrue()
        ->and($reached->reach()->reaches($money))->toBeTrue()
        ->and(array_map(static fn(Reason $reason): string => $reason->text(), [...$reached->reach()->reasons()]))->toBe([$why])
        ->and($unchanged->reach()->isEverywhere())->toBeFalse();
});

it('reaches nothing for a file beside a config that reads no other file, as a JSON config reads none', function () use ($money): void {
    $reached = reachedReading(
        DecidingConfig::unread(...),
        ConfigReads::none(),
        Changes::of(Change::modified(Path::of('settings/shared.php'), Lines::of(Line::of(1)))),
        ['settings/shared.php' => '<?php'],
        ['settings/shared.php' => '<?php // before'],
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
        ChangeBase::since(Revision::ref('base')),
        Flows::trees(),
        $adapters,
        Flows::settings(),
        reachedSuite($checkout, $adapters),
        CoverageMap::empty(),
        DecidingConfig::unread(),
    );

    expect($reached->reach()->reaches($held))->toBeTrue()
        ->and($reached->changed())->toEqual(CannotTell::because('base is not a revision this repository has.'));
});

/** What a run reaches whose change is read from this checkout, since this base. */
function reachedFrom(ChangeSourceFake $checkout, ChangeBase $base): Reached
{
    $adapters = Flows::adapters(Flows::project(), [], $checkout);

    return Reached::since(
        $base,
        Flows::trees(),
        $adapters,
        Flows::settings(),
        reachedSuite($checkout, $adapters),
        CoverageMap::empty(),
        DecidingConfig::unread(),
    );
}

it('reaches only what changed since its last run, gives the new code since the ref, and carries its own results alone for what changed since the ref', function () use ($money, $held): void {
    $sinceRef = Changes::of(
        Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(3))),
        Change::modified(Path::of('src/Held.php'), Lines::of(Line::of(2))),
    );
    $checkout = new ChangeSourceFake(Revision::ref('base'), $sinceRef, [
        Revision::workingTree()->name() => Flows::FILES,
        'base' => Flows::FILES,
        'last-run' => Flows::FILES,
    ])->changedFrom(Revision::ref('last-run'), Changes::of(Change::modified(Path::of('src/Held.php'), Lines::of(Line::of(2)))));

    $reached = reachedFrom($checkout, ChangeBase::lastRun(JudgedCommits::of('last-run'), Revision::ref('base')));

    expect($reached->reach()->reaches($held))->toBeTrue()
        ->and($reached->reach()->reaches($money))->toBeFalse()
        ->and($reached->changed())->toEqual($sinceRef)
        ->and([...$reached->ownOnly()])->toEqual([Path::of('src/Money.php'), Path::of('src/Held.php')]);
});

it('reads its change since the ref where git cannot read the commit its last run judged, as after a force-push', function () use ($money, $held): void {
    $sinceRef = Changes::of(Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(3))));
    $checkout = new ChangeSourceFake(Revision::ref('base'), $sinceRef, [
        Revision::workingTree()->name() => Flows::FILES,
        'base' => Flows::FILES,
    ]);

    $reached = reachedFrom($checkout, ChangeBase::lastRun(JudgedCommits::of('rewritten'), Revision::ref('base')));

    expect($reached->reach()->reaches($money))->toBeTrue()
        ->and($reached->reach()->reaches($held))->toBeFalse()
        ->and($reached->changed())->toEqual($sinceRef)
        ->and([...$reached->ownOnly()])->toBe([]);
});

it('reads its change from the tree a gone last run\'s merge makes again, where that is the tree it held, and since the ref where it is not', function (bool $sameTree, bool $exact) use ($money, $held): void {
    $sinceRef = Changes::of(
        Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(3))),
        Change::modified(Path::of('src/Held.php'), Lines::of(Line::of(2))),
    );
    $judged = JudgedCommits::of('gone-merge', 'main-then', 'head-then');
    $rebuilt = $sameTree ? $judged->tree() : JudgedCommits::treeOf('another merge');
    $checkout = new ChangeSourceFake(Revision::ref('base'), $sinceRef, [
        Revision::workingTree()->name() => Flows::FILES,
        'base' => Flows::FILES,
        $rebuilt->id() => Flows::FILES,
    ])->rebuilding(
        Revision::ref('gone-merge'),
        $rebuilt,
        Changes::of(Change::modified(Path::of('src/Held.php'), Lines::of(Line::of(2)))),
    );

    $reached = reachedFrom($checkout, ChangeBase::lastRun($judged, Revision::ref('base')));

    expect($reached->reach()->reaches($held))->toBeTrue()
        ->and($reached->reach()->reaches($money))->toBe(! $exact)
        ->and($reached->changed())->toEqual($sinceRef)
        ->and(count($reached->ownOnly()))->toBe($exact ? 2 : 0);
})->with([
    'the tree it held' => [true, true],
    'another tree' => [false, false],
]);
