<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\GateApplication;
use NightWorksIO\MutationGate\Cli\NotFinished;
use NightWorksIO\MutationGate\Tests\Support\Printed;
use Symfony\Component\Console\Command\Command;
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
