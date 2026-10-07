<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\AloneRuns;
use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\Control;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Remembered;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShellFake;

afterEach(function (): void {
    Scratch::sweep();
});

it('fails a control whose file cannot be served unmutated, running nothing for it, and runs the rest', function (): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'src/Money.php', "<?php\n\nfinal class Money\n{\n}\n");
    $at = Project::at($root, Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'));
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $tests = Paths::of(Path::of('tests/MoneySpec.php'));

    $passed = new AloneRuns($at, $shell, new Remembered())->pass(
        [
            Control::of(Path::of('src/Gone.php'), $tests, WholeSuite::tests()),
            Control::of(Path::of('src/Money.php'), $tests, WholeSuite::tests()),
        ],
        MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()),
        Unlimited::time(),
        sprintf('%s/results.jsonl', $root),
    );

    expect($passed)->toBe([false, true])
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

    $passed = new AloneRuns($at, $shell, new Remembered())->pass(
        [
            Control::of(Path::of('src/Money.php'), $tests, WholeSuite::tests()),
            Control::of(Path::of('src/Tax.php'), $tests, WholeSuite::tests()),
        ],
        MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()),
        Unlimited::time(),
        sprintf('%s/results.jsonl', $root),
    );

    expect($passed)->toBe([true, false])
        ->and($shell->commands())->toHaveCount(2);
});
