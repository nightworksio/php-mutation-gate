<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use DateTimeImmutable;

use function implode;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Infection\Import\Choices;
use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\Config\Effective;
use NightWorksIO\MutationGate\Cli\Config\Formats;
use NightWorksIO\MutationGate\Cli\Config\InfectionFile;
use NightWorksIO\MutationGate\Cli\Config\NoConfigFile;
use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\GatePin;
use NightWorksIO\MutationGate\Core\Config\Format;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Extension\Extensions;

use function sprintf;

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
    private const string KEPT = '%s is already here, so init makes only what --ci and --editor ask for.';

    public static function command(
        string $project,
        Extensions $extensions,
        Effective $effective,
        Formats $formats,
        DateTimeImmutable $now,
        GatePin $gate,
    ): Command {
        return Additions::options(self::formatted(new Command('init')))
            ->setDescription(
                'Write a config holding what zero-config found, the CI with --ci and the editor with --editor',
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
            ): int {
                $from = $input->getOption('from');
                $file = $from === false ? NotGiven::value() : InfectionFile::named(is_string($from) ? $from : '');
                $setting = new Setting($project, $extensions, $effective, $formats, $now, $gate);

                $additions = Additions::asked($input, $project);

                return $additions instanceof CannotJudge
                    ? Failed::because($output, $additions)
                    : self::run($input, $output, $setting, $file, $additions);
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
    ): int {
        $format = $input->getOption('format');
        $given = CommandLine::from($input);
        $destination = Destination::of($setting->project, $given->config, is_string($format) ? $format : '');
        $file = $from instanceof InfectionFile ? $from->in($setting->project) : NotGiven::value();

        if ($destination instanceof CannotJudge || $file instanceof CannotJudge) {
            return Failed::because($output, $destination instanceof CannotJudge ? $destination : $file);
        }

        $given = $file instanceof Path ? $given->choosing(Choices::runner()->use()->value()) : $given;
        $settings = self::settings($setting, $given, $destination->existing(), $file, $additions);
        $written = self::made($setting, $settings, $destination, $file, $additions);

        if (! is_string($written)) {
            return Failed::because($output, $written);
        }

        $output->writeln($written, OutputInterface::OUTPUT_RAW);

        return ExitCode::Passed->value;
    }

    /**
     * The config, where none is here, the CI definition, where `--ci` asks for one, and the editor's files, where
     * `--editor` names one, said; or why they were not all made. A CI definition that cannot be made, such as one
     * whose file is already here, stops them all before anything is written; a file that then cannot be written
     * is said after what was.
     */
    private static function made(
        Setting $setting,
        Settings|Invalid|CannotJudge $settings,
        Destination $destination,
        Path|NotGiven $from,
        Additions $additions,
    ): string|Invalid|CannotJudge {
        $ci = $additions->ci();
        $definition = CiDefinition::packaged($setting->project, $setting->extensions, $setting->gate);
        $existing = $destination->existing();
        $prepared = $ci instanceof CiRequest && $settings instanceof Settings
            ? $definition->prepared($ci, $settings, kept: $existing instanceof Path)
            : NotGiven::value();
        $config = match (true) {
            $prepared instanceof CannotJudge => $prepared,
            ! $settings instanceof Settings => $settings,
            $existing instanceof Path
                => sprintf(self::KEPT, $existing->relativeTo(Path::of($setting->project))->value()),
            default => ConfigWriting::written(
                $setting,
                $settings,
                $destination,
                $from,
                $prepared instanceof PreparedCi ? $prepared->config() : Layer::none(),
            ),
        };

        if (! is_string($config) || ! $settings instanceof Settings) {
            return $config;
        }

        $made = $prepared instanceof PreparedCi ? $definition->made($prepared, $settings->ci()) : '';

        return self::said($config, $made, is_string($made) ? $additions->editorMade($setting->project) : '');
    }

    /** What was made, said in order; or, after what was, why the first that could not be made was not. */
    private static function said(string|CannotJudge ...$outcomes): string|CannotJudge
    {
        $said = [];

        foreach ($outcomes as $outcome) {
            if ($outcome instanceof CannotJudge) {
                return CannotJudge::because(implode("\n", [...$said, $outcome->why()]));
            }

            $said = $outcome === '' ? $said : [...$said, $outcome];
        }

        return implode("\n", $said);
    }

    /**
     * The settings `init` writes from: what zero-config finds where no config is here; the config here where
     * `--ci` asks for the CI definition alone; and otherwise none, since `init` never replaces a config.
     */
    private static function settings(
        Setting $setting,
        CommandLine $given,
        Path|NoConfigFile|CannotJudge $existing,
        Path|NotGiven $from,
        Additions $additions,
    ): Settings|Invalid|CannotJudge {
        return match (true) {
            $existing instanceof NoConfigFile => $setting->effective->settings($given->withoutConfig()),
            $existing instanceof Path && $additions->any() && ! $from instanceof Path
                => $setting->effective->settings($given),
            default => self::refused($existing),
        };
    }

    private static function refused(Path|CannotJudge $existing): CannotJudge
    {
        return $existing instanceof CannotJudge
            ? $existing
            : CannotJudge::because(sprintf(
                '%s is already here, and init writes a config only where there is none.',
                $existing->value(),
            ));
    }
}
