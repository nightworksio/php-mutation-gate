<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function is_string;

use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\File\Path;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * `--publish-dir`, which `verdict` and a one-process `run` take (ADR-0009): the directory the badge and trend are
 * written into on the default branch, `.mutation-gate/publish` where it names none.
 */
final readonly class PublishDir
{
    public const string OPTION = 'publish-dir';

    private const string SAID = 'Where the badge and trend are written, .mutation-gate/publish by default';

    /** The command, taking `--publish-dir`. */
    public static function option(Command $command): Command
    {
        return $command->addOption(self::OPTION, mode: InputOption::VALUE_REQUIRED, description: self::SAID);
    }

    /** What the flow runs with, publishing into the directory the options name, where they name one. */
    public static function composed(
        Composed|Invalid|CannotJudge $composed,
        InputInterface $input,
    ): Composed|Invalid|CannotJudge {
        $directory = $input->getOption(self::OPTION);

        return $composed instanceof Composed && is_string($directory) && $directory !== ''
            ? $composed->publishingInto(Path::of($directory))
            : $composed;
    }
}
