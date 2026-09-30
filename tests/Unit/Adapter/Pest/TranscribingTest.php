<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\Transcribing;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Tests\Support\ShellFake;

it('keeps what every command printed, in another directory too, and answers as the shell it runs over', function (): void {
    $shell = new ShellFake(static fn(Command $command): Ran => Ran::finished(succeeded: true, output: implode(' ', array_slice($command->arguments(), 1))));
    $transcribing = Transcribing::over($shell);

    $ran = $transcribing->run(Command::php(Withheld::standard(), 'first'));
    $transcribing->in('/project/packages/billing')->run(Command::php(Withheld::standard(), 'second'));

    expect($ran)->toEqual(Ran::finished(succeeded: true, output: 'first'))
        ->and($transcribing->printed())->toBe("first\nsecond")
        ->and($shell->directories())->toBe(['/project/packages/billing']);
});

it('has printed nothing before it runs anything', function (): void {
    expect(Transcribing::over(ShellFake::answering(Ran::finished(succeeded: true, output: 'unread')))->printed())->toBe('');
});
