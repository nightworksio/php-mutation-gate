<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Console\InertOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

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
