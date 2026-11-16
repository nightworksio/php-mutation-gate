<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\CoverageEntries;
use NightWorksIO\MutationGate\Cli\Flow\Inventory;
use NightWorksIO\MutationGate\Cli\Flow\KeptCoverage;
use NightWorksIO\MutationGate\Cli\Flow\QuickRound;
use NightWorksIO\MutationGate\Cli\Flow\Suite;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Order\Saved;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\MisbehavingRunner;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

it('takes why the tests are unknown as the round\'s first answer, and the changed files alone where it knows no entries', function (): void {
    $round = QuickRound::after(Changes::of(Change::deleted(Path::of('src/Money.php'))));
    $unknown = CannotJudge::because('the map cannot be read');

    expect($round->saved($unknown, CoverageMap::of()))->toBe($unknown)
        ->and($round->saved(NotGiven::value(), CoverageMap::of()))
        ->toEqual(Saved::of(Paths::of(Path::of('src/Money.php')), Paths::none()));
});

/**
 * The entries of the fixture project with these files beside its own, and its suite.
 *
 * @param  array<string, string>                                 $files
 * @return list{CoverageEntries|CannotJudge|NotGiven, Suite|CannotJudge}
 */
function quickRoundProject(array $files, object ...$ports): array
{
    $project = Flows::project();

    foreach ($files as $path => $contents) {
        Scratch::write($project, $path, $contents);
    }

    $all = [...Flows::FILES, ...$files];
    $checkout = new ChangeSourceFake(Revision::ref(Flows::HEAD), Changes::none(), [Revision::workingTree()->name() => $all, Flows::HEAD => $all]);
    $settings = Flows::settings();
    $adapters = Flows::adapters($project, [], $checkout, ...$ports);
    $inventory = Inventory::of($adapters, $settings);
    $entries = $inventory instanceof Inventory ? new KeptCoverage($adapters, $settings, Flows::setup())->entries($inventory) : $inventory;

    return [$entries, $inventory instanceof Inventory ? $inventory->suite : $inventory];
}

it('takes the files saved and every file the tests of each saved test file run, and tells the saved sources and test files apart', function (): void {
    [$entries, $suite] = quickRoundProject([
        'src/Other.php' => "<?php\n\nfinal class Other\n{\n}\n",
        'tests/HeldTest.php' => "<?php\n\nit('doubles', fn () => expect(2)->toBe(2));\n",
        'tests/OtherTest.php' => "<?php\n\nit('halves', fn () => expect(1)->toBe(1));\n",
        'tests/Helpers.php' => "<?php\n",
        'README.md' => "# Fixture\n",
    ]);
    $saved = [
        Path::of('src/Money.php'), Path::of('src/Held.php'), Path::of('tests/MoneyTest.php'), Path::of('tests/HeldTest.php'),
        Path::of('tests/Helpers.php'), Path::of('README.md'),
    ];
    $round = QuickRound::after(Changes::of(...array_map(static fn(Path $path): Change => Change::modified($path, Lines::of(Line::of(1))), $saved)));
    $map = CoverageMap::of(
        CoveredLine::of(Path::of('src/Money.php'), 3, 'MoneyTest::adds'),
        CoveredLine::of(Path::of('src/Held.php'), 3, 'HeldTest::doubles'),
        CoveredLine::of(Path::of('src/Other.php'), 3, 'OtherTest::halves'),
    );

    expect($entries)->toBeInstanceOf(CoverageEntries::class)
        ->and($suite)->toBeInstanceOf(Suite::class);

    if (! $entries instanceof CoverageEntries || ! $suite instanceof Suite) {
        return;
    }

    expect($round->saved($entries, $map))->toEqual(Saved::of(Paths::of(...$saved), Paths::of(Path::of('src/Money.php'), Path::of('src/Held.php'))))
        ->and($round->sourcesChanged($entries, $suite))->toEqual(Paths::of(Path::of('src/Money.php'), Path::of('src/Held.php')))
        ->and($round->testsChanged($entries))->toEqual([Path::of('tests/MoneyTest.php'), Path::of('tests/HeldTest.php')])
        ->and(QuickRound::after(Changes::of(Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(1)))))->saved($entries, $map))
        ->toEqual(Saved::of(Paths::of(Path::of('src/Money.php')), Paths::none()));
});

it('asks the runner for the tests of the saved test files only where a test file was saved', function (): void {
    $refusing = new MisbehavingRunner(
        ScriptedRunner::fixture(),
        testsIn: static fn(): CannotJudge => CannotJudge::because('The runner cannot list them.'),
    );
    [$entries] = quickRoundProject([], $refusing);
    $saved = static fn(string $path): Saved|CannotJudge|NotGiven => $entries instanceof CoverageEntries
        ? QuickRound::after(Changes::of(Change::modified(Path::of($path), Lines::of(Line::of(1)))))->saved($entries, CoverageMap::of())
        : $entries;

    expect($saved('src/Money.php'))->toEqual(Saved::of(Paths::of(Path::of('src/Money.php')), Paths::none()))
        ->and($saved('tests/MoneyTest.php'))->toEqual(CannotJudge::because('The runner cannot list them.'));
});
