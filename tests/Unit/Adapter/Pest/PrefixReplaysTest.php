<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Invocation;
use NightWorksIO\MutationGate\Adapter\Pest\PrefixReplay;
use NightWorksIO\MutationGate\Adapter\Pest\PrefixReplays;
use NightWorksIO\MutationGate\Adapter\Pest\Printed;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Placed;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Adapter\Pest\Remembered;
use NightWorksIO\MutationGate\Adapter\Pest\ReplayVerdict;
use NightWorksIO\MutationGate\Core\Config\TestOrder;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\OrderDigest;
use NightWorksIO\MutationGate\Core\Mutant\Prefix;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\KillSearch;
use NightWorksIO\MutationGate\Core\Order\Ordering;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\PestCases;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShellFake;

afterEach(function (): void {
    Scratch::sweep();
});

/** The order a kill's own run took its two tests in, as the plugin digests it. */
function replayedOrder(string $second = 'T::b'): string
{
    return OrderDigest::of(TestId::of('T::a'), TestId::of($second))->value();
}

/**
 * The replay of a run of src/Money.php that loaded tests/MoneySpec.php and
 * ran two tests up to its last killer, its mutant allowed these seconds.
 */
function moneyReplay(Seconds|Unmeasured $limit = new Unmeasured()): PrefixReplay
{
    $files = Paths::of(Path::of('tests/MoneySpec.php'));

    return PrefixReplay::of(
        Path::of('src/Money.php'),
        '/tmp/mutations/abc',
        ['vendor/bin/pest', '--bail', '--filter=MoneySpec', '/p/tests/MoneySpec.php'],
        $files,
        2,
        Prefix::keyOf($files, replayedOrder()),
        $limit,
    );
}

/** A shell whose replays record these lines and then end so. */
function replaying(Ran $ended, string ...$lines): ShellFake
{
    return new ShellFake(static function (Command $command) use ($ended, $lines): Ran {
        file_put_contents(sprintf('%s', $command->environment()[GateVariable::Results->value]), implode('', $lines));

        return $ended;
    });
}

/**
 * What the replays of a run of this project say, through this shell.
 *
 * @return list<ReplayVerdict>
 */
function replayVerdicts(Project $at, ShellFake $shell, Seconds|Unlimited $left = new Unlimited(), PrefixReplay ...$replays): array
{
    return new PrefixReplays($at, $shell, new Remembered())->verdicts(
        $replays === [] ? [moneyReplay()] : array_values($replays),
        PestCases::money(),
        $left,
        PestCases::results($at),
    );
}

it('lets a kill stand only where its replay ran as many tests, none failing, ended well, in the same order', function (Ran $ended, string $records, ReplayVerdict $verdict): void {
    $at = PestCases::project();

    expect(replayVerdicts($at, replaying($ended, $records)))->toBe([$verdict]);
})->with([
    'every condition holds' => [
        fn(): Ran => Ran::finished(succeeded: true, output: ''),
        fn(): string => implode('', [RecordLine::ran('/r/copy.php', 2), RecordLine::stopped('/r/copy.php', 2, replayedOrder())]),
        ReplayVerdict::Stands,
    ],
    'a test fails unmutated' => [
        fn(): Ran => Ran::finished(succeeded: true, output: ''),
        fn(): string => implode('', [RecordLine::killed('/r/copy.php', 'T::b', Placed::unplaced(1)), RecordLine::ran('/r/copy.php', 2), RecordLine::stopped('/r/copy.php', 2, replayedOrder())]),
        ReplayVerdict::Failed,
    ],
    'another number of tests ran' => [
        fn(): Ran => Ran::finished(succeeded: true, output: ''),
        fn(): string => implode('', [RecordLine::ran('/r/copy.php', 3), RecordLine::stopped('/r/copy.php', 2, replayedOrder())]),
        ReplayVerdict::OtherCount,
    ],
    'the run failed with no test failing' => [
        fn(): Ran => Ran::finished(succeeded: false, output: ''),
        fn(): string => implode('', [RecordLine::ran('/r/copy.php', 2), RecordLine::stopped('/r/copy.php', 2, replayedOrder())]),
        ReplayVerdict::FailedRun,
    ],
    'another order' => [
        fn(): Ran => Ran::finished(succeeded: true, output: ''),
        fn(): string => implode('', [RecordLine::ran('/r/copy.php', 2), RecordLine::stopped('/r/copy.php', 2, replayedOrder('T::c'))]),
        ReplayVerdict::OtherOrder,
    ],
    'no stop recorded' => [
        fn(): Ran => Ran::finished(succeeded: true, output: ''),
        fn(): string => implode('', [RecordLine::ran('/r/copy.php', 2)]),
        ReplayVerdict::OtherOrder,
    ],
    'stopped at its deadline' => [
        fn(): Ran => Ran::stopped(''),
        fn(): string => implode('', [RecordLine::ran('/r/copy.php', 2), RecordLine::stopped('/r/copy.php', 2, replayedOrder())]),
        ReplayVerdict::NoTime,
    ],
]);

it('starts the run again as Pest started it, its file served unmutated, its records beside the results, its order the copy\'s, stopped after its reach', function (): void {
    $at = PestCases::project();
    $shell = replaying(Ran::finished(succeeded: true, output: ''));
    $request = PestCases::money()->searching(KillSearch::of(Ordering::of(TestOrder::KillersFirst, KillHistory::none()), MatrixKind::FirstKiller));

    new PrefixReplays($at, $shell, new Remembered())->verdicts([moneyReplay()], $request, Unlimited::time(), PestCases::results($at));
    $printed = Printed::of(Contents::of((string) file_get_contents(sprintf('%s/src/Money.php', $at->root()))), Path::of('src/Money.php'));
    $unmutated = sprintf('%s/.mutation-gate/pest/originals/%s.php', $at->root(), hash('xxh3', $printed instanceof Contents ? $printed->text() : ''));
    $command = $shell->commands()[0];

    expect($command->arguments())->toBe(Invocation::installedIn(Path::of('vendor'))
        ->replaying(['--bail', '--filter=MoneySpec', '/p/tests/MoneySpec.php'], Withheld::standard())->arguments())
        ->and($command->environment())->toMatchArray([
            Recorder::MUTANT => sprintf('%s/src/Money.php', $at->root()),
            Recorder::MUTATED => $unmutated,
            GateVariable::OrderOf->value => '/tmp/mutations/abc',
            GateVariable::StopAfter->value => '2',
            GateVariable::KillMatrix->value => MatrixKind::FirstKiller->value,
            GateVariable::Order->value => $at->order(),
        ])
        ->and(dirname(sprintf('%s', $command->environment()[GateVariable::Results->value])))->toBe(dirname(PestCases::results($at)));
});

it('starts a replay with no earlier replay\'s records of the same run left where it writes its own', function (): void {
    $at = PestCases::project();
    $found = [];
    $shell = new ShellFake(static function (Command $command) use (&$found): Ran {
        $records = sprintf('%s', $command->environment()[GateVariable::Results->value]);
        $found[] = is_file($records);
        file_put_contents($records, RecordLine::ran('/r/copy.php', 2), FILE_APPEND);

        return Ran::finished(succeeded: true, output: '');
    });

    replayVerdicts($at, $shell);
    replayVerdicts($at, $shell);

    expect($found)->toBe([false, false]);
});

it('runs one replay for two kills of one run, keeps what it said, and runs none where no time is left', function (): void {
    $at = PestCases::project();
    $shell = replaying(Ran::finished(succeeded: true, output: ''), RecordLine::ran('/r/copy.php', 2), RecordLine::stopped('/r/copy.php', 2, replayedOrder()));
    $replays = new PrefixReplays($at, $shell, new Remembered());

    $first = $replays->verdicts([moneyReplay(), moneyReplay()], PestCases::money(), Unlimited::time(), PestCases::results($at));
    $again = $replays->verdicts([moneyReplay()], PestCases::money(), Unlimited::time(), PestCases::results($at));
    $late = replayVerdicts(PestCases::project(), $shell, Seconds::of(0.0));

    expect([$first, $again, $late])->toBe([[ReplayVerdict::Stands, ReplayVerdict::Stands], [ReplayVerdict::Stands], [ReplayVerdict::NoTime]])
        ->and($shell->commands())->toHaveCount(1);
});

it('holds a replay to its kill\'s limit where that is shorter than the time left, and tells a stop at it from one at the time left', function (Seconds|Unlimited $left, float $deadline, ReplayVerdict $verdict): void {
    $at = PestCases::project();
    $shell = replaying(Ran::stopped(''), RecordLine::ran('/r/copy.php', 1));

    expect(replayVerdicts($at, $shell, $left, moneyReplay(Seconds::of(3.0))))->toBe([$verdict])
        ->and($shell->commands()[0]->deadline())->toEqual(Seconds::of($deadline));
})->with([
    'the limit first' => [fn(): Unlimited => Unlimited::time(), 3.0, ReplayVerdict::OverLimit],
    'the limit before a longer time left' => [fn(): Seconds => Seconds::of(9.0), 3.0, ReplayVerdict::OverLimit],
    'the time left first' => [fn(): Seconds => Seconds::of(2.0), 2.0, ReplayVerdict::NoTime],
    'the time left at the limit' => [fn(): Seconds => Seconds::of(3.0), 3.0, ReplayVerdict::NoTime],
]);

it('keeps what a replay said under one limit apart from a replay of the same run under another', function (): void {
    $at = PestCases::project();
    $shell = replaying(Ran::stopped(''));
    $replays = new PrefixReplays($at, $shell, new Remembered());

    $short = $replays->verdicts([moneyReplay(Seconds::of(3.0))], PestCases::money(), Unlimited::time(), PestCases::results($at));
    $long = $replays->verdicts([moneyReplay(Seconds::of(9.0))], PestCases::money(), Unlimited::time(), PestCases::results($at));

    expect([$short, $long])->toBe([[ReplayVerdict::OverLimit], [ReplayVerdict::OverLimit]])
        ->and($shell->commands())->toHaveCount(2);
});

it('cannot vouch for a kill whose file it cannot serve unmutated', function (): void {
    $at = PestCases::project();
    unlink(sprintf('%s/src/Money.php', $at->root()));

    expect(replayVerdicts($at, replaying(Ran::finished(succeeded: true, output: ''))))->toBe([ReplayVerdict::Unserved]);
});
