<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\Failed;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;

it('says why it cannot judge on the error stream, and exits 2', function (): void {
    $console = new ConsoleOutput();
    $errors = new BufferedOutput();
    $console->setErrorOutput($errors);

    expect(Failed::because($console, CannotJudge::because('No tree found.')))->toBe(2)
        ->and($errors->fetch())->toBe("No tree found.\n");
});

it('prints every problem on a line of its own, at its path where it has one, and exits 2', function (): void {
    $output = new BufferedOutput();
    $invalid = Invalid::because(
        Problem::at('reports[0].with.channel', 'expected a channel name, got nothing'),
        Problem::at('', 'needs a channel'),
    );

    expect(Failed::because($output, $invalid))->toBe(2)
        ->and($output->fetch())
        ->toBe("reports[0].with.channel: expected a channel name, got nothing\nneeds a channel\n");
});

it('writes to the output itself where it has no error stream', function (): void {
    $output = new BufferedOutput();

    expect(Failed::because($output, CannotJudge::because('No tree found.')))->toBe(2)
        ->and($output->fetch())->toBe("No tree found.\n");
});
