<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\TailShell;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\Workers;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShellFake;

afterEach(function (): void {
    Scratch::sweep();
});

/** A project in a scratch directory. */
function tailProject(): Project
{
    return Project::at(Scratch::directory(), Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'));
}

/** A request whose pool starts its runs as this says. */
function tailRequest(Workers $workers): MutationRequest
{
    return MutationRequest::of(Paths::of(Path::of('src')), WholeSuite::tests())->across(Pool::of(ProcessCount::of(2), $workers));
}

it('forks the tail\'s runs only where runner.workers forks the tail under pest.patch, and runs them as the shell does otherwise', function (Patching $patching, Workers $workers, bool $forks): void {
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));

    expect(TailShell::of($shell, tailProject(), $patching, tailRequest($workers)) instanceof TailShell)->toBe($forks);
})->with([
    'fork, patched' => [fn(): Patching => Patching::on(Group::named('canary')), Workers::Fork, true],
    'mutants, patched' => [fn(): Patching => Patching::on(Group::named('canary')), Workers::Mutants, false],
    'fresh, patched' => [fn(): Patching => Patching::on(Group::named('canary')), Workers::Fresh, false],
    'fork, unpatched' => [fn(): Patching => Patching::off(), Workers::Fork, false],
]);

it('runs a single command, and one in another directory, as its shell does', function (): void {
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: 'ran'));
    $tail = TailShell::of($shell, tailProject(), Patching::on(Group::named('canary')), tailRequest(Workers::Fork));
    $pest = Command::pest('vendor/bin/pest', Withheld::standard(), '--bail');

    expect($tail->run($pest)->output())->toBe('ran')
        ->and($shell->commands()[0]->arguments())->toBe($pest->arguments())
        ->and($tail->in('/elsewhere'))->toBeInstanceOf(TailShell::class)
        ->and($shell->directories())->toBe(['/elsewhere']);
});
