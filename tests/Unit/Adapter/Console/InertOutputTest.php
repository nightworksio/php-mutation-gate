<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Console\InertOutput;
use NightWorksIO\MutationGate\Adapter\Console\InertStream;
use NightWorksIO\MutationGate\Adapter\Console\NoSection;
use NightWorksIO\MutationGate\Adapter\Console\NotInert;
use NightWorksIO\MutationGate\Cli\Gate;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * The console, its standard output and standard error each written to memory, and what each holds once this has
 * written to it.
 *
 * @param  Closure(InertOutput): void $write
 * @return array{string, string}
 */
function inertlyWritten(Closure $write): array
{
    $output = new InertOutput();
    $out = fopen('php://memory', 'w+');
    $err = fopen('php://memory', 'w+');
    $stream = new ReflectionProperty(StreamOutput::class, 'stream');
    $stream->setValue($output, $out);
    $stream->setValue($output->getErrorOutput(), $err);
    $write($output);

    return [
        is_resource($out) ? (string) stream_get_contents($out, offset: 0) : '',
        is_resource($err) ? (string) stream_get_contents($err, offset: 0) : '',
    ];
}

it('writes standard output and standard error so no line starts a command', function (): void {
    $output = new InertOutput();
    $errors = $output->getErrorOutput();
    $out = fopen('php://memory', 'w+');
    $err = fopen('php://memory', 'w+');
    $stream = new ReflectionProperty(StreamOutput::class, 'stream');
    $stream->setValue($output, $out);
    $stream->setValue($errors, $err);

    $output->writeln("Failures\n::error::injected", OutputInterface::OUTPUT_RAW);
    $errors->writeln('  ::warning::injected', OutputInterface::OUTPUT_RAW);
    $output->write('<info>::notice::x</info>');

    expect($errors)->toBeInstanceOf(StreamOutput::class)
        ->and(is_resource($out) ? stream_get_contents($out, offset: 0) : $out)->toBe("Failures\n\\::error::injected\n\\::notice::x")
        ->and(is_resource($err) ? stream_get_contents($err, offset: 0) : $err)->toBe("  \\::warning::injected\n");
});

it('draws no section, which would write past it', function (): void {
    expect(static fn(): mixed => new InertOutput()->section())
        ->toThrow(NoSection::drawn());
});

it('drops every control character from what it is given to write, raw or formatted, and keeps its own colour', function (): void {
    $hostile = "MoneyTest::fits\e[2K\e]1338;url='https://attacker.example/p.png'\x07\e[8m";
    $written = inertlyWritten(static function (InertOutput $output) use ($hostile): void {
        $output->setDecorated(decorated: true);
        $output->writeln([$hostile], OutputInterface::OUTPUT_RAW);
        $output->write(sprintf('<info>passed</info> %s', $hostile));
        $output->getErrorOutput()->writeln($hostile, OutputInterface::OUTPUT_PLAIN);
    });
    $shown = "MoneyTest::fits[2K]1338;url='https://attacker.example/p.png'[8m";

    expect($written)->toBe([sprintf("%s\n\e[32mpassed\e[39m %s", $shown, $shown), sprintf("%s\n", $shown)]);
});

it('starts no command after its own colour', function (): void {
    $written = inertlyWritten(static function (InertOutput $output): void {
        $output->setDecorated(decorated: true);
        $output->write('<info>::notice::x</info>');
        $output->getErrorOutput()->write('<error>::error::x</error>');
    });

    expect($written)->toBe(["\e[32m\\::notice::x\e[39m", "\e[37;41m\\::error::x\e[39;49m"]);
});

it('keeps the colour --ansi asks for, on both streams', function (): void {
    $project = Scratch::directory();
    $written = inertlyWritten(static function (InertOutput $output) use ($project): void {
        $gate = Gate::in($project, sprintf('%s/vendor', $project));
        $gate->run(new ArgvInput(['mutation-gate', 'list', '--ansi', '--no-extensions']), $output, $output->getErrorOutput());
        $gate->run(new ArgvInput(['mutation-gate', 'nothing', '--ansi', '--no-extensions']), $output, $output->getErrorOutput());
    });

    expect($written[0])->toContain("\e[33mAvailable commands:\e[39m")
        ->and($written[1])->toContain("\e[37;41m");
});

it('wraps a stream in its own decoration', function (bool $decorated): void {
    $memory = fopen('php://memory', 'w+');

    expect(is_resource($memory) && InertStream::over(new StreamOutput($memory, decorated: $decorated))->isDecorated() === $decorated)->toBeTrue();
})->with([true, false]);

it('makes inert a standard error it is handed later, and refuses one that is no stream', function (): void {
    $output = new InertOutput();
    $memory = fopen('php://memory', 'w+');
    $output->setErrorOutput(is_resource($memory) ? new StreamOutput($memory) : new BufferedOutput());
    $output->getErrorOutput()->writeln("::error::x\e[2K", OutputInterface::OUTPUT_RAW);

    expect(is_resource($memory) ? stream_get_contents($memory, offset: 0) : $memory)->toBe("\\::error::x[2K\n")
        ->and(static fn() => $output->setErrorOutput(new BufferedOutput()))->toThrow(NotInert::class);
});

it('starts no command across two writes that make one line, and leaves a line no write makes one as it is', function (): void {
    $written = inertlyWritten(static function (InertOutput $output): void {
        $output->write(':', options: OutputInterface::OUTPUT_RAW);
        $output->writeln(':error::x', OutputInterface::OUTPUT_RAW);
        $output->write('#', options: OutputInterface::OUTPUT_RAW);
        $output->writeln('#[error]x', OutputInterface::OUTPUT_RAW);
        $output->write('  ', options: OutputInterface::OUTPUT_RAW);
        $output->writeln('::warning::x', OutputInterface::OUTPUT_RAW);
        $output->write('#', options: OutputInterface::OUTPUT_RAW);
        $output->write('#', options: OutputInterface::OUTPUT_RAW);
        $output->writeln('[error]x', OutputInterface::OUTPUT_RAW);
        $output->write('Running ', options: OutputInterface::OUTPUT_RAW);
        $output->write("the tests\nin ", options: OutputInterface::OUTPUT_RAW);
        $output->writeln('src', OutputInterface::OUTPUT_RAW);
        $output->getErrorOutput()->write('##', options: OutputInterface::OUTPUT_RAW);
        $output->getErrorOutput()->writeln('vso[task.complete]', OutputInterface::OUTPUT_RAW);
    });

    expect($written)->toBe([
        ":\n:error::x\n#\n#[error]x\n  \\::warning::x\n##\n[error]x\nRunning the tests\nin src\n",
        "##\nvso[task.complete]\n",
    ]);
});
