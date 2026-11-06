<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function is_string;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * `--ledger=<file>`: the `ledger.json.gz` a command that reads a ledger reads in place of the default branch's.
 *
 * @internal
 */
final readonly class LedgerOption
{
    private const string LEDGER = 'ledger';

    public static function on(Command $command): Command
    {
        return $command->addOption(
            self::LEDGER,
            mode: InputOption::VALUE_REQUIRED,
            description: 'Read this ledger.json.gz in place of the default branch\'s ledger',
        );
    }

    /** The file the option names, from the project; none where it names none. */
    public static function given(InputInterface $input): Path|NotGiven
    {
        $file = $input->getOption(self::LEDGER);

        return is_string($file) && $file !== '' ? Path::of($file) : NotGiven::value();
    }
}
