<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Whether `init` writes the files it makes, or prints every one of them,
 * the config and the `.gitignore` line among them, with `--dry-run` or its
 * other name, `--stdout` (ADR-0015, ADR-0017 decision 1).
 */
enum Output
{
    case Written;

    case Printed;

    /** A file printed whole under its name. */
    public const string FILE = "%s:\n\n%s";

    private const string DRY_RUN = 'dry-run';

    private const string STDOUT = 'stdout';

    public static function option(Command $command): Command
    {
        return $command
            ->addOption(self::DRY_RUN, mode: InputOption::VALUE_NONE, description: 'Print every file, and write none')
            ->addOption(self::STDOUT, mode: InputOption::VALUE_NONE, description: 'Another name for --dry-run');
    }

    public static function asked(InputInterface $input): self
    {
        return $input->getOption(self::DRY_RUN) === true || $input->getOption(self::STDOUT) === true
            ? self::Printed
            : self::Written;
    }
}
