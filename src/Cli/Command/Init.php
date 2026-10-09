<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use DateTimeImmutable;

use function is_string;

use NightWorksIO\MutationGate\Cli\Config\Detected;
use NightWorksIO\MutationGate\Cli\Config\Effective;
use NightWorksIO\MutationGate\Cli\Config\Formats;
use NightWorksIO\MutationGate\Cli\Config\InfectionFile;
use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Init\Asking;
use NightWorksIO\MutationGate\Cli\Init\InitQuestions;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\GatePin;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Format;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Extension\Extensions;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `init`: a config file holding exactly what zero-config found, so adopting
 * one changes nothing until somebody edits it (ADR-0002): the preset, the
 * runner and the trees. With `--from`, or as `import`, an Infection config
 * seeds it too, and every key of that file is said to be imported, left in
 * place or dropped (ADR-0016). It also keeps `.mutation-gate/` out of git.
 */
final readonly class Init
{
    public static function command(
        string $project,
        Extensions $extensions,
        Effective $effective,
        Formats $formats,
        DateTimeImmutable $now,
        GatePin $gate,
        Detected $detected,
        Variables $environment,
    ): Command {
        return InitQuestions::options(Additions::options(self::formatted(new Command('init'))))
            ->setDescription(
                'Write a config of what zero-config found; --ci, --editor and --hook set up the CI, editor and hooks',
            )
            ->addOption(
                'from',
                mode: InputOption::VALUE_OPTIONAL,
                description: 'Start from an Infection config: this file, or the one Infection would read',
                default: false,
            )
            ->setCode(static function (InputInterface $input, OutputInterface $output) use (
                $project,
                $extensions,
                $effective,
                $formats,
                $now,
                $gate,
                $detected,
                $environment,
            ): int {
                $from = $input->getOption('from');
                $file = $from === false ? NotGiven::value() : InfectionFile::named(is_string($from) ? $from : '');
                $setting = new Setting($project, $extensions, $effective, $formats, $now, $gate);
                $questions = new InitQuestions(Asking::at($input, $output, $environment), $project, $detected);

                $additions = Additions::asked($input, $project);

                return $additions instanceof CannotJudge
                    ? Failed::because($output, $additions)
                    : self::run($input, $output, $setting, $file, $additions, $questions);
            });
    }

    /** `import`: `init --from`, with the Infection config as an argument. */
    public static function import(
        string $project,
        Extensions $extensions,
        Effective $effective,
        Formats $formats,
        DateTimeImmutable $now,
        GatePin $gate,
    ): Command {
        return self::formatted(new Command('import'))
            ->setDescription('Write a config from an Infection config and what zero-config found')
            ->addArgument(
                'file',
                mode: InputArgument::OPTIONAL,
                description: 'The Infection config, where it is not the one Infection would read',
                default: '',
            )
            ->setCode(static function (InputInterface $input, OutputInterface $output) use (
                $project,
                $extensions,
                $effective,
                $formats,
                $now,
                $gate,
            ): int {
                $file = $input->getArgument('file');
                $setting = new Setting($project, $extensions, $effective, $formats, $now, $gate);

                return self::run(
                    $input,
                    $output,
                    $setting,
                    InfectionFile::named(is_string($file) ? $file : ''),
                    Additions::none(),
                    NotGiven::value(),
                );
            });
    }

    private static function formatted(Command $command): Command
    {
        return $command->addOption(
            'format',
            mode: InputOption::VALUE_REQUIRED,
            description: Format::words(),
            default: Format::Php->value,
        );
    }

    private static function run(
        InputInterface $input,
        OutputInterface $output,
        Setting $setting,
        InfectionFile|NotGiven $from,
        Additions $additions,
        InitQuestions|NotGiven $questions,
    ): int {
        $said = new InitRun($input, $setting, $additions, $questions)->said($from);

        if (! is_string($said)) {
            return Failed::because($output, $said);
        }

        $output->writeln($said, OutputInterface::OUTPUT_RAW);

        return ExitCode::Passed->value;
    }
}
