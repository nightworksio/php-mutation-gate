<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\Argument;
use NightWorksIO\MutationGate\Cli\Command\CommandText;
use NightWorksIO\MutationGate\Cli\Command\MissingCommandText;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

/** A command line of an option and an argument each with a default of text, and of each with none. */
function commandTextDefinition(): InputDefinition
{
    return new InputDefinition([
        new InputArgument(Argument::Path->value, InputArgument::OPTIONAL, default: 'src'),
        new InputArgument(Argument::File->value, InputArgument::OPTIONAL),
        new InputOption('format', mode: InputOption::VALUE_REQUIRED, default: 'text'),
        new InputOption('write', mode: InputOption::VALUE_NONE),
    ]);
}

it('gives the text an option or an argument holds, written or by default', function (): void {
    $written = new ArrayInput([Argument::Path->value => 'app', '--format' => 'json'], commandTextDefinition());
    $defaulted = new ArrayInput([], commandTextDefinition());

    expect([
        CommandText::option($written, 'format'),
        CommandText::argument($written, Argument::Path),
        CommandText::option($defaulted, 'format'),
        CommandText::argument($defaulted, Argument::Path),
    ])->toBe(['json', 'app', 'text', 'src']);
});

it('refuses an option whose definition gives no text', function (): void {
    CommandText::option(new ArrayInput([], commandTextDefinition()), 'write');
})->throws(MissingCommandText::class, 'The option --write holds no text, though its definition always gives some.');

it('refuses an argument whose definition gives no text', function (): void {
    CommandText::argument(new ArrayInput([], commandTextDefinition()), Argument::File);
})->throws(MissingCommandText::class, 'The argument file holds no text, though its definition always gives some.');
