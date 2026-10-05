<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Keyed;

use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;

use function sprintf;

use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * The one option a command that holds the gate's keys takes, a directory, as its command line gives it: the
 * command's name and that option alone, so any other argument or option is refused rather than ignored.
 */
final readonly class DirectoryOption
{
    private const string REFUSED = '%s refuses its command line: %s';

    /** The directory the option names, else this one; or why this command's command line is refused. */
    public static function in(
        InputInterface $input,
        string $command,
        string $option,
        Path $otherwise,
    ): string|CannotJudge {
        try {
            $input->bind(new InputDefinition([
                new InputArgument('command', InputArgument::REQUIRED),
                new InputOption($option, mode: InputOption::VALUE_REQUIRED),
            ]));
        } catch (ExceptionInterface $refused) {
            return CannotJudge::because(sprintf(self::REFUSED, $command, $refused->getMessage()));
        }

        $given = $input->getOption($option);

        return is_string($given) && $given !== '' ? $given : $otherwise->value();
    }
}
