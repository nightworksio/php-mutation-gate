<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\GateApplication;
use NightWorksIO\MutationGate\Cli\NotFinished;
use NightWorksIO\MutationGate\Core\Serve\ServeFrame;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\Printed;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ServedCommands;
use NightWorksIO\MutationGate\Tests\Support\ServedProjects;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * An application whose one command, `act`, ends as the closure it is given does.
 *
 * @param Closure(): int $act
 */
function applicationActing(Closure $act): ApplicationTester
{
    $application = new GateApplication('mutation-gate', 'test');
    $application->setAutoExit(boolean: false);
    $application->addCommand(new Command('act')->setCode(static fn(): int => $act()));

    return new ApplicationTester($application);
}

it('ends a command it could not finish with could-not-judge, and says what stopped it', function (Closure $act, array $line, string $said): void {
    $tester = applicationActing($act);

    $code = $tester->run($line);
    $printed = Printed::by($tester->getOutput());

    expect($code)->toBe(ExitCode::CannotJudge->value)
        ->and($printed)->toContain($said)
        ->and($printed)->not->toContain('NotFinished');
})->with([
    'a mistake nobody anticipated' => [static fn(): int => throw new Error('A mistake in the code.'), ['command' => 'act'], 'A mistake in the code.'],
    'an error' => [static fn(): int => intdiv(1, 0), ['command' => 'act'], 'Division by zero'],
    'an option it does not know' => [static fn(): int => 0, ['command' => 'act', '--nope' => true], 'The "--nope" option does not exist.'],
    'a command it does not know' => [static fn(): int => 0, ['command' => 'nope'], 'Command "nope" is not defined.'],
]);

it('ends a command that finished with its own exit code', function (int $code): void {
    expect(applicationActing(static fn(): int => $code)->run(['command' => 'act']))->toBe($code);
})->with([0, 1, 2]);

it('keeps what stopped the command, and itself where nothing did', function (): void {
    $failed = new Error('Wrong.');

    expect(NotFinished::by($failed)->cause())->toBe($failed)
        ->and(NotFinished::by($failed)->getMessage())->toBe('Wrong.')
        ->and(NotFinished::by($failed)->getCode())->toBe(ExitCode::CannotJudge->value)
        ->and(new NotFinished('Alone.')->cause())->toBeInstanceOf(NotFinished::class);
});

/**
 * A command a daemon serves run twice by an application that hands it to a daemon: first while a daemon stood in for
 * answers with 9, then with none; the command ends with 3 where it runs here. The two exit codes.
 *
 * @return array{int, int}
 */
function servedTwice(): array
{
    $project = ServedProjects::enteredAt(FlowCommands::project());
    $application = new GateApplication('mutation-gate', 'test');
    $application->setAutoExit(boolean: false);
    $application->addCommand(new Command('pre-commit')->setCode(static fn(): int => 3));
    $application->servedBy(ServedCommands::serving($project));
    ServedProjects::answering(ServeFrame::exited(9)->line());
    $served = $application->run(new ArgvInput(['gate', 'pre-commit']), new BufferedOutput());
    ServedProjects::socket()->removed();
    $local = $application->run(new ArgvInput(['gate', 'pre-commit']), new BufferedOutput());
    ServedProjects::left();
    Scratch::sweep();

    return [$served, $local];
}

it('runs a command a daemon serves in the daemon, ending with its exit code, and in this process where none serves it', function (): void {
    expect(servedTwice())->toBe([9, 3]);
});
