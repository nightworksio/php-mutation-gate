<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\ChangedTests;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Tests\Support\Replay;

/** The changed Cli command test that runs most of the gate. */
const REPLAYED_COMMAND = 'tests/Unit/Cli/Command/RunCommandTest.php';

/** @return list<string> */
function replayedReasons(Reach $reach): array
{
    return array_map(static fn(Reason $reason): string => $reason->text(), [...$reach->reasons()]);
}

it('replays a merged pull request, reaching the units and cutting the shards its changed test files ask for', function (string $name, ChangedTests $mode, int $units, int $shards): void {
    $replay = Replay::of($name);
    $reach = $replay->reach($mode);

    expect($replay->reached($reach))->toBe($units)
        ->and($replay->shards($reach))->toBe($shards);
})->with([
    'what a newcomer sees, by whole files' => ['pull-request-517', ChangedTests::File, 850, 16],
    'what a newcomer sees, narrowed' => ['pull-request-517', ChangedTests::Narrow, 734, 15],
    'comments beyond GitHub, by whole files' => ['pull-request-499', ChangedTests::File, 890, 15],
    'comments beyond GitHub, narrowed' => ['pull-request-499', ChangedTests::Narrow, 776, 15],
]);

it('says of each changed test file of a replay what it reaches, and why', function (): void {
    $replay = Replay::of('pull-request-517');

    expect(replayedReasons($replay->reach(ChangedTests::File)))
        ->toContain('`tests/Unit/Cli/Command/RunCommandTest.php` changed, so the 761 files its tests run are reached.')
        ->and(replayedReasons($replay->reach(ChangedTests::Narrow)))->toContain(
            '`tests/Unit/Cli/Command/RunCommandTest.php` changed 2 of its 43 tests, so the 671 files they run are reached.',
            '`tests/Unit/Adapter/Console/ConsoleReportTest.php` changed 2 of its 19 tests, so the 114 files they run are reached.',
            '`tests/Unit/Cli/Flow/VerdictTimingsTest.php` changed code its tests share, so the 322 files all its tests run are reached.',
            '`tests/Unit/Adapter/Native/CoverageTest.php` changed code its tests share, so the 77 files all its tests run are reached.',
        )
        ->and(replayedReasons(Replay::of('pull-request-499')->reach(ChangedTests::Narrow)))->toContain(
            '`tests/Unit/Core/Config/Settings/SectionsTest.php` changed 5 of its 54 tests, so the 112 files they run are reached.',
            '`tests/Unit/Adapter/GitLab/MergeRequestNoteTest.php` is new, so the 169 files all its tests run are reached.',
            '`tests/Unit/Core/Report/MarkdownTest.php` was renamed, so the 190 files all its tests run are reached.',
        );
});

it('widens a replayed test file to every unit its tests run where the change also moved what they share', function (string $from, string $to): void {
    $replay = Replay::of('pull-request-517');
    $now = $replay->now(REPLAYED_COMMAND);
    $reach = $replay->reachWith(ChangedTests::Narrow, REPLAYED_COMMAND, str_replace($from, $to, $now));

    expect($now)->toContain($from)
        ->and(replayedReasons($reach))->toContain('`tests/Unit/Cli/Command/RunCommandTest.php` changed code its tests share, so the 753 files all its tests run are reached.')
        ->and($replay->reached($reach))->toBe(813)
        ->and($replay->shards($reach))->toBe(16);
})->with([
    'a helper' => ['Tree::at(Path::of(\'src\'), Floor::of($floor), Package::at(Path::root())),', 'Tree::at(Path::of(\'src\'), Floor::of($floor + 0.0), Package::at(Path::root())),'],
    'its teardown' => ["afterEach(function (): void {\n    Scratch::sweep();\n});", "afterEach(function (): void {\n    Scratch::sweep();\n    Scratch::sweep();\n});"],
    'a setup added' => ["afterEach(function (): void {", "beforeEach(function (): void {\n    Scratch::sweep();\n});\n\nafterEach(function (): void {"],
    'an import' => ["use NightWorksIO\\MutationGate\\Tests\\Support\\Verdicts;\n", "use NightWorksIO\\MutationGate\\Tests\\Support\\Verdicts;\nuse NightWorksIO\\MutationGate\\Tests\\Support\\Tree;\n"],
]);

it('falls back to what the runner judges by a replayed test file whose tests the map holds no id of, and says so', function (): void {
    $replay = Replay::of('pull-request-517');
    $reach = $replay->reachUnplacing(REPLAYED_COMMAND);

    expect(replayedReasons($reach))->toContain('The coverage map places no test in `tests/Unit/Cli/Command/RunCommandTest.php` by its id, so the 761 files the runner judges by it are reached.')
        ->and($replay->reached($reach))->toBe(819)
        ->and($replay->shards($reach))->toBe(16);
});
