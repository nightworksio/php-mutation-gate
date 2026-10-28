<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\AloneRuns;
use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\Control;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Remembered;
use NightWorksIO\MutationGate\Core\Control\ControlEnd;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\JudgingSuites;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Tests\Support\JUnitLogs;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShellFake;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * How each control ended, in their order.
 *
 * @param  list<ControlRun> $runs
 * @return list<ControlEnd>
 */
function aloneEnds(array $runs): array
{
    return array_map(static fn(ControlRun $run): ControlEnd => $run->end(), $runs);
}

it('runs no control whose file cannot be served unmutated, saying why, and runs the rest', function (): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'src/Money.php', "<?php\n\nfinal class Money\n{\n}\n");
    $at = Project::at($root, Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'));
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $tests = Paths::of(Path::of('tests/MoneySpec.php'));

    $passed = new AloneRuns($at, $shell, new Remembered())->ended(
        [
            Control::of(Path::of('src/Gone.php'), $tests, WholeSuite::tests()),
            Control::of(Path::of('src/Money.php'), $tests, WholeSuite::tests()),
        ],
        MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()),
        Unlimited::time(),
        sprintf('%s/results.jsonl', $root),
    );

    expect(aloneEnds($passed))->toBe([ControlEnd::Unrun, ControlEnd::Passed])
        ->and($passed[0]->why())->toContain('src/Gone.php')
        ->and($shell->commands())->toHaveCount(1)
        ->and($shell->commands()[0]->environment()['PEST_MUTATION_TESTING'])->toBe(sprintf('%s/src/Money.php', $root));
});

it('runs a control for each file served, though the tests and their files are the same', function (): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'src/Money.php', "<?php\n\nfinal class Money\n{\n}\n");
    Scratch::write($root, 'src/Tax.php', "<?php\n\nfinal class Tax\n{\n}\n");
    $at = Project::at($root, Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'));
    $shell = new ShellFake(static fn(Command $command): Ran => Ran::finished(
        succeeded: $command->environment()['PEST_MUTATION_TESTING'] !== sprintf('%s/src/Tax.php', $root),
        output: '',
    ));
    $tests = Paths::of(Path::of('tests/MoneySpec.php'));

    $passed = new AloneRuns($at, $shell, new Remembered())->ended(
        [
            Control::of(Path::of('src/Money.php'), $tests, WholeSuite::tests()),
            Control::of(Path::of('src/Tax.php'), $tests, WholeSuite::tests()),
        ],
        MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()),
        Unlimited::time(),
        sprintf('%s/results.jsonl', $root),
    );

    expect(aloneEnds($passed))->toBe([ControlEnd::Passed, ControlEnd::Failed])
        ->and($shell->commands())->toHaveCount(2);
});

it('runs a control of no test file over the judging suites, and one of test files over those files', function (): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'src/Money.php', "<?php\n\nfinal class Money\n{\n}\n");
    $at = Project::at($root, Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'));
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());
    $request = $request->narrowedTo(
        $request->files(),
        Narrowing::none()->amongSuites(JudgingSuites::holding(Suites::listed('Unit'), Suites::listed('Process'))),
    )->reusingCoverage(Handed::maps(Path::of('shard'), Path::of('whole')));

    new AloneRuns($at, $shell, new Remembered())->ended(
        [
            Control::of(Path::of('src/Money.php'), Paths::none(), WholeSuite::tests()),
            Control::of(Path::of('src/Money.php'), Paths::of(Path::of('tests/MoneySpec.php')), WholeSuite::tests()),
        ],
        $request,
        Unlimited::time(),
        sprintf('%s/results.jsonl', $root),
    );

    expect($shell->commands()[0]->arguments())->toContain('--testsuite=Unit')
        ->and(implode(' ', $shell->commands()[1]->arguments()))->not->toContain('--testsuite');
});

it('runs no control where no time is left, and each where some is', function (float $left, bool $runs): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'src/Money.php', "<?php\n\nfinal class Money\n{\n}\n");
    $at = Project::at($root, Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'));
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));

    $passed = new AloneRuns($at, $shell, new Remembered())->ended(
        [Control::of(Path::of('src/Money.php'), Paths::of(Path::of('tests/MoneySpec.php')), WholeSuite::tests())],
        MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()),
        Seconds::of($left),
        sprintf('%s/results.jsonl', $root),
    );

    expect(aloneEnds($passed))->toBe([$runs ? ControlEnd::Passed : ControlEnd::Unrun])
        ->and($shell->commands())->toHaveCount($runs ? 1 : 0)
        ->and(is_dir(sprintf('%s/originals', $root)))->toBe($runs);
})->with([
    'none left, serving no copy' => [0.0, false],
    'half a second left' => [0.5, true],
]);

it('names the tests a failed control\'s JUnit log records failing', function (): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'src/Money.php', "<?php\n\nfinal class Money\n{\n}\n");
    $at = Project::at($root, Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'));
    $shell = new ShellFake(static function (Command $command): Ran {
        JUnitLogs::failing($command->arguments(), 'testAdds', 'tests/MoneyTest.php', 'Tests\\MoneyTest');

        return Ran::finished(succeeded: false, output: '');
    });

    $ended = new AloneRuns($at, $shell, new Remembered())->ended(
        [Control::of(Path::of('src/Money.php'), Paths::of(Path::of('tests/MoneyTest.php')), WholeSuite::tests())],
        MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()),
        Unlimited::time(),
        sprintf('%s/results.jsonl', $root),
    );

    expect($ended)->toEqual([ControlRun::failed(TestIds::of(TestId::of('Tests\MoneyTest::testAdds')))]);
});

it('runs a control unlogged, naming no failing test, where something it cannot clear stands where its log goes', function (): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'src/Money.php', "<?php\n\nfinal class Money\n{\n}\n");
    Scratch::write($root, '.mutation-gate/pest/alone/junit-0.xml/kept', 'kept');
    $at = Project::at($root, Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'));
    $shell = ShellFake::answering(Ran::finished(succeeded: false, output: ''));

    $ended = new AloneRuns($at, $shell, new Remembered())->ended(
        [Control::of(Path::of('src/Money.php'), Paths::of(Path::of('tests/MoneyTest.php')), WholeSuite::tests())],
        MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()),
        Unlimited::time(),
        sprintf('%s/results.jsonl', $root),
    );

    expect($ended)->toEqual([ControlRun::failed(TestIds::none())])
        ->and(implode(' ', $shell->commands()[0]->arguments()))->not->toContain('--log-junit');
});

it('remembers a control\'s run apart for each set of withheld variables', function (): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'src/Money.php', "<?php\n\nfinal class Money\n{\n}\n");
    $at = Project::at($root, Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'));
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $runs = new AloneRuns($at, $shell, new Remembered());
    $controls = [Control::of(Path::of('src/Money.php'), Paths::of(Path::of('tests/MoneySpec.php')), WholeSuite::tests())];
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());

    $runs->ended($controls, $request, Unlimited::time(), sprintf('%s/results.jsonl', $root));
    $runs->ended($controls, $request, Unlimited::time(), sprintf('%s/results.jsonl', $root));
    $runs->ended($controls, $request->withholding(Withheld::of('DEPLOY_*')), Unlimited::time(), sprintf('%s/results.jsonl', $root));

    expect($shell->commands())->toHaveCount(2);
});
