<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/** Whether `init` writes the CI and editor files it makes, or prints them with `--stdout` (ADR-0015). */
enum Output
{
    case Written;

    case Printed;

    private const string STDOUT = 'stdout';

    public static function option(Command $command): Command
    {
        return $command->addOption(
            self::STDOUT,
            mode: InputOption::VALUE_NONE,
            description: 'Print the CI and editor files, not write them',
        );
    }

    public static function asked(InputInterface $input): self
    {
        return $input->getOption(self::STDOUT) === true ? self::Printed : self::Written;
    }
}
