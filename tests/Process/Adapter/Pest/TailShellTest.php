<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\TailShell;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Client;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\PidFile;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Seat;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\OwnScript;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\Workers;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShellFake;

$holds = ['holds:src/Adapter/Pest/TailShell.php', 'holds:src/Adapter/Pest/Warm/Pool.php'];

afterEach(function (): void {
    Scratch::sweep();
});

it('runs each command side by side through the client of its slot\'s warm worker, started for the runs and ended once they are done', function (): void {
    $root = Scratch::directory();
    $running = [];
    $shell = new ShellFake(static function (Command $command) use (&$running): Ran {
        $arguments = $command->arguments();
        $directory = $arguments[count(OwnScript::PestClient->started()) + 1];
        $running = [PidFile::isRunning(Seat::at($directory, 1)->pid()), PidFile::isRunning(Seat::at($directory, 2)->pid())];

        return Ran::finished(succeeded: true, output: '');
    });
    $project = Project::at($root, Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'));
    $request = MutationRequest::of(Paths::of(Path::of('src')), WholeSuite::tests())->across(Pool::of(ProcessCount::of(2), Workers::Fork));
    $tail = TailShell::of($shell, $project, Patching::on(Group::named('canary')), $request);

    $ends = $tail->sideBySide(
        WorkerSlots::of(ProcessCount::of(2), 'tail'),
        Unlimited::time(),
        Command::pest('vendor/bin/pest', Withheld::standard(), '--bail'),
    );
    $arguments = $shell->commands()[0]->arguments();
    $client = OwnScript::PestClient->started();

    expect([...$ends][0]->succeeded())->toBeTrue()
        ->and($running)->toBe([true, true])
        ->and(array_slice($arguments, 1, count($client)))->toBe($client)
        ->and(array_slice($arguments, count($client) + 2))->toBe([Client::SLOT, 'vendor/bin/pest', '--bail'])
        ->and(is_dir($arguments[count($client) + 1]))->toBeFalse();
})->group(...$holds);

it('starts the tail\'s workers in a directory with nothing an earlier run left, so none is told it is done before it starts', function (): void {
    $root = Scratch::directory();
    $project = Project::at($root, Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'));
    $left = sprintf('%s/.mutation-gate/pest/tail.warm', (string) realpath($root));
    mkdir($left, 0o700, recursive: true);
    touch(Seat::stopIn($left));
    $running = false;
    $stale = true;
    $shell = new ShellFake(static function () use ($left, &$running, &$stale): Ran {
        $running = PidFile::isRunning(Seat::at($left, 1)->pid());
        $stale = file_exists(Seat::stopIn($left));

        return Ran::finished(succeeded: true, output: '');
    });
    $request = MutationRequest::of(Paths::of(Path::of('src')), WholeSuite::tests())->across(Pool::of(ProcessCount::single(), Workers::Fork));

    TailShell::of($shell, $project, Patching::on(Group::named('canary')), $request)
        ->sideBySide(WorkerSlots::alone(), Unlimited::time(), Command::pest('vendor/bin/pest', Withheld::standard()));

    expect($running)->toBeTrue()
        ->and($stale)->toBeFalse();
})->group(...$holds);

it('forks runs that scan the same ini files from one set of workers, started scanning them, and runs that scan others from a set of their own', function (): void {
    $root = Scratch::directory();
    $forkedIn = [];
    $shell = new ShellFake(static function (Command $command) use (&$forkedIn): Ran {
        $directory = $command->arguments()[count(OwnScript::PestClient->started()) + 1];
        $forkedIn[] = [$directory, PidFile::isRunning(Seat::at($directory, 1)->pid())];

        return Ran::finished(succeeded: true, output: '');
    });
    $project = Project::at($root, Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'));
    $request = MutationRequest::of(Paths::of(Path::of('src')), WholeSuite::tests())->across(Pool::of(ProcessCount::single(), Workers::Fork)->tail());
    $capped = Command::pest('vendor/bin/pest', Withheld::standard())->with([MemoryCap::SCAN_DIR => ':/cap']);

    TailShell::of($shell, $project, Patching::on(Group::named('canary')), $request)
        ->sideBySide(WorkerSlots::alone(), Unlimited::time(), $capped, Command::pest('vendor/bin/pest', Withheld::standard()), $capped);

    expect(array_column($forkedIn, 1))->toBe([true, true, true])
        ->and($forkedIn[0][0])->toBe($forkedIn[2][0])
        ->and($forkedIn[0][0])->not->toBe($forkedIn[1][0])
        ->and(is_dir($forkedIn[0][0]) || is_dir($forkedIn[1][0]))->toBeFalse();
})->group(...$holds);
