<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Command;
use NightWorksIO\MutationGate\Adapter\Infection\Ran;
use NightWorksIO\MutationGate\Adapter\Infection\Transcribing;
use NightWorksIO\MutationGate\Tests\Support\InfectionShellFake;

it('keeps what every command printed, in another directory too, and answers as the shell it runs over', function (): void {
    $shell = new InfectionShellFake(static fn(Command $command): Ran => Ran::finished(succeeded: true, output: implode(' ', array_slice($command->arguments(), 1))));
    $transcribing = Transcribing::over($shell);

    $ran = $transcribing->run(Command::php('first'));
    $transcribing->in('/project/packages/billing')->run(Command::php('second'));

    expect($ran)->toEqual(Ran::finished(succeeded: true, output: 'first'))
        ->and($transcribing->printed())->toBe("first\nsecond")
        ->and($shell->directories())->toBe(['/project/packages/billing']);
});

it('has printed nothing before it runs anything', function (): void {
    expect(Transcribing::over(InfectionShellFake::answering(Ran::finished(succeeded: true, output: 'unread')))->printed())->toBe('');
});
