<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\FlowOptions;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Cost\CostBasis;
use NightWorksIO\MutationGate\Core\Cost\Estimated;
use NightWorksIO\MutationGate\Core\Coverage\Judges;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Plan\Weighed;
use NightWorksIO\MutationGate\Core\Plan\Workload;
use NightWorksIO\MutationGate\Core\Reach\Layout;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Reach\Reaching;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Sources;
use NightWorksIO\MutationGate\Core\Reach\TestRuns;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Tree as Checkout;

/** Where each test file's stand-in unit is: the one file its tests run. */
const FIXTURES_JUDGED = 'judged';

/**
 * A merged pull request's replay: its exact diff, the fixture project it
 * changed, the tests that name it, and what the gate planned of it with the
 * default branch's map and ledger, with reach.fixtures and before it.
 */
function fixturesReplay(int $pullRequest): Node
{
    return Node::decode((string) file_get_contents(Checkout::at(sprintf('tests/Fixtures/Replays/%d.json', $pullRequest))));
}

/**
 * Each path of a replay's diff, with the status git gave it.
 *
 * @return array<string, string>
 */
function fixturesDiff(int $pullRequest): array
{
    $diff = [];

    foreach (fixturesReplay($pullRequest)->field('changes')->items() as $change) {
        [$status, $path] = $change->items();
        $diff[$path->text()] = $status->text();
    }

    return $diff;
}

/** A replay's diff, each path as git named its status. */
function fixturesChanges(int $pullRequest): Changes
{
    $diff = fixturesDiff($pullRequest);

    return Changes::of(...array_map(
        static fn(string $path, string $status): Change => $status === 'A'
            ? Change::added(Path::of($path), Lines::none())
            : Change::modified(Path::of($path), Lines::none()),
        array_keys($diff),
        $diff,
    ));
}

/**
 * The PHP files a replay's reach reads, as this checkout holds them: the
 * runner contract's tests and support, and the tests and fixture files the
 * diff changed.
 *
 * @return list<string>
 */
function fixturesFiles(int $pullRequest): array
{
    $listed = glob(Checkout::at('tests/Contract/Runner/*.php'));
    $runner = array_map(
        static fn(string $file): string => substr($file, strlen(Checkout::root()) + 1),
        is_array($listed) ? $listed : [],
    );
    $changed = array_filter(
        array_keys(fixturesDiff($pullRequest)),
        static fn(string $file): bool => str_starts_with($file, 'tests/') && str_ends_with($file, '.php'),
    );

    return array_values(array_unique([...$runner, ...$changed]));
}

/** The layout of this repository, with the fixture projects its own config names where it is asked to. */
function fixturesLayout(bool $configured): Layout
{
    $layout = Layout::standard(Paths::of(Path::of('tests/Pest.php')));
    $settings = Configs::settings((string) file_get_contents(Checkout::at('mutation-gate.json')));

    foreach ($configured ? $settings->reach()->fixtures() : [] as $glob) {
        $layout = $layout->withFixtures($glob);
    }

    return $layout;
}

/**
 * What a replay's diff reaches in this checkout, where each file of test
 * cases runs a stand-in unit of its own alone, and the test files whose
 * stand-in units it reaches.
 *
 * @return array{Reach, list<string>}
 */
function fixturesReached(int $pullRequest, bool $configured): array
{
    $files = fixturesFiles($pullRequest);
    $sources = Sources::none();
    $judges = Judges::none();

    foreach ($files as $file) {
        $sources = $sources->withNow(Path::of($file), Contents::of((string) file_get_contents(Checkout::at($file))));
        $judges = str_ends_with($file, 'Test.php')
            ? $judges->judging(Path::of(FIXTURES_JUDGED)->child(Path::of($file)), Paths::of(Path::of($file)))
            : $judges;
    }

    $root = Package::at(Path::root());
    $trees = Trees::of(Tree::at(Path::of('src'), Undeclared::floor(), $root), Tree::at(Path::of(FIXTURES_JUDGED), Undeclared::floor(), $root));
    $reach = new Reaching(fixturesLayout($configured), $trees)->of(fixturesChanges($pullRequest), $judges, $sources, TestRuns::wholeFiles());

    return [$reach, array_values(array_filter(
        $files,
        static fn(string $test): bool => $reach->reaches(Unit::file(Path::of(FIXTURES_JUDGED)->child(Path::of($test)))),
    ))];
}

/** @return list<string> */
function fixturesSaid(Reach $reach): array
{
    return array_map(static fn(Reason $reason): string => $reason->text(), iterator_to_array($reach->reasons(), preserve_keys: false));
}

/** How many shards the gate's own config cuts this many units into, at this cost the ledger learned of them all. */
function fixturesShards(int $units, float $learned, float $opening): int|string
{
    $cut = FlowOptions::configuredCut(Configs::settings((string) file_get_contents(Checkout::at('mutation-gate.json'))))
        ->opening(Seconds::of($opening));
    $root = Package::at(Path::root());
    $each = Estimated::of(Seconds::of($learned / $units), CostBasis::Learned);
    $work = Workload::of(...array_map(
        static fn(int $unit): Weighed => Weighed::of(Unit::file(Path::of(sprintf('src/Unit%d.php', $unit))), $root, $each),
        range(1, $units),
    ));
    $shards = $cut->cut($work, Trees::of(Tree::at(Path::of('src'), Undeclared::floor(), $root)));

    return $shards instanceof Shards ? count($shards) : $shards->why();
}

it('reaches, for a pull request\'s exact diff, only the units of the tests it changed and of those that name the fixture it changed, and says why', function (int $pullRequest): void {
    $replay = fixturesReplay($pullRequest);
    $fixture = $replay->field('fixture')->text();
    $usedBy = array_map(static fn(Node $test): string => $test->text(), $replay->field('usedBy')->items());
    [$reach, $tests] = fixturesReached($pullRequest, configured: true);
    $files = array_keys(fixturesDiff($pullRequest));
    $changedTests = array_filter($files, static fn(string $file): bool => str_ends_with($file, 'Test.php'));
    $inFixture = array_values(array_filter($files, static fn(string $file): bool => str_starts_with($file, sprintf('%s/', $fixture))));
    $said = array_map(
        static fn(string $file): string => sprintf(
            '`%s` is in the fixture `%s`, which %d tests use, so the %d files they run are reached.',
            $file,
            $fixture,
            count($usedBy),
            count($usedBy),
        ),
        $inFixture,
    );

    expect($reach->isEverywhere())->toBeFalse()
        ->and($reach->reaches(Unit::file(Path::of('src/Core/Verdict/Verdict.php'))))->toBeFalse()
        ->and($tests)->toEqualCanonicalizing(array_values(array_unique([...$changedTests, ...$usedBy])))
        ->and(array_values(array_intersect(fixturesSaid($reach), $said)))->toBe($said)
        ->and($inFixture)->not->toBeEmpty();
})->with([485, 481]);

it('reaches every unit of the project for the same diff where reach.fixtures names no fixture, as the pull request ran', function (int $pullRequest): void {
    [$reach] = fixturesReached($pullRequest, configured: false);
    $spec = array_find(array_keys(fixturesDiff($pullRequest)), static fn(string $file): bool => str_ends_with($file, 'Spec.php'));

    expect($reach->reaches(Unit::file(Path::of('src/Core/Verdict/Verdict.php'))))->toBeTrue()
        ->and(fixturesSaid($reach))->toContain(sprintf('`%s` runs code when it is loaded, so every unit of the project is reached.', $spec));
})->with([485, 481]);

it('cuts the units the replay\'s plan ran, at what the ledger learned of them, into the shards it planned, and every unit into the most there may be', function (int $pullRequest): void {
    $planned = fixturesReplay($pullRequest)->field('planned');
    $before = fixturesReplay($pullRequest)->field('before');
    $opening = $planned->field('openingSeconds')->number();

    expect(fixturesShards($planned->field('units')->integer(), $planned->field('learnedSeconds')->number(), $opening))
        ->toBe($planned->field('shards')->integer())
        ->and(fixturesShards($before->field('units')->integer(), $before->field('learnedSeconds')->number(), $opening))
        ->toBe($before->field('shards')->integer());
})->with([485, 481]);
