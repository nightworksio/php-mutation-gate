<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function array_filter;
use function basename;

use DateTimeImmutable;

use function dirname;
use function implode;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Infection\Import\Choices;
use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\Config\Effective;
use NightWorksIO\MutationGate\Cli\Config\Formats;
use NightWorksIO\MutationGate\Cli\Config\Imported;
use NightWorksIO\MutationGate\Cli\Config\InfectionFile;
use NightWorksIO\MutationGate\Cli\Config\NoConfigFile;
use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\GatePin;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Format;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Import\Import;
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
    private const string ZERO_CONFIG = 'what zero-config found';

    private const string AND_ZERO_CONFIG = '%s and what zero-config found';

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
            description: 'php, json, yaml or neon',
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
     * `--editor` names one, said; or why they were not all made. A CI file already here stops them all, since
     * `init` never replaces a file.
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
        $clash = $ci instanceof CiRequest && $settings instanceof Settings
            ? $definition->clash($ci, $settings)
            : NotGiven::value();
        $existing = $destination->existing();
        $config = match (true) {
            $clash instanceof CannotJudge => $clash,
            ! $settings instanceof Settings => $settings,
            $existing instanceof Path
                => sprintf(self::KEPT, $existing->relativeTo(Path::of($setting->project))->value()),
            default => self::written($setting, $settings, $destination, $from, CiDefinition::configOf($ci)),
        };

        if (! is_string($config) || ! $settings instanceof Settings) {
            return $config;
        }

        $made = $ci instanceof CiRequest ? $definition->made($ci, $settings) : '';
        $editor = is_string($made) ? $additions->editorMade($setting->project) : $made;

        return match (true) {
            $editor instanceof CannotJudge => $editor,
            default => implode(
                "\n",
                array_filter([$config, $made, $editor], static fn(string $said): bool => $said !== ''),
            ),
        };
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

    /** What was written, said as a sentence, and what became of each imported key, or why nothing was. */
    private static function written(
        Setting $setting,
        Settings $settings,
        Destination $destination,
        Path|NotGiven $from,
        Layer $more,
    ): string|Invalid|CannotJudge {
        $import = self::seeded($setting, $settings, $from, $more);
        $text = $import instanceof Import
            ? $setting->formats->file(
                $import->layer(),
                $destination->format(),
                ConfigFile::at($destination->file(), Path::of($setting->project)),
            )
            : $import;

        if (! is_string($text)) {
            return $text;
        }

        $source = $from instanceof Path ? sprintf(self::AND_ZERO_CONFIG, $from->value()) : self::ZERO_CONFIG;
        $said = self::write($setting->project, $destination, $text, $source);

        return is_string($said) && $from instanceof Path
            ? sprintf("%s\n%s", $said, $import->report($from->value()))
            : $said;
    }

    /** What zero-config found, with the Infection config imported over it where one is named. */
    private static function seeded(
        Setting $setting,
        Settings $settings,
        Path|NotGiven $from,
        Layer $more,
    ): Import|Invalid|CannotJudge {
        $zeroConfig = ZeroConfig::layer($setting->extensions, $settings);

        if (! $zeroConfig instanceof Layer) {
            return $zeroConfig;
        }

        $found = $zeroConfig->over($more);

        return $from instanceof Path
            ? Imported::from($setting->project, $from, $found, $setting->now)
            : Import::of($found);
    }

    /** The config written, said as a sentence, with where what it holds came from. */
    private static function write(
        string $project,
        Destination $destination,
        string $text,
        string $source,
    ): string|CannotJudge {
        $file = $destination->file()->value();
        $written = Directory::at(dirname($file))->write(Path::of(basename($file)), Contents::of($text));
        $ignored = $written instanceof CannotJudge ? $written : IgnoredWorkspace::in(Directory::at($project));

        return match (true) {
            $ignored instanceof CannotJudge => $ignored,
            $ignored => sprintf(
                'Wrote %s with %s, and added %s to .gitignore.',
                $destination->shown(),
                $source,
                IgnoredWorkspace::line(),
            ),
            default => sprintf('Wrote %s with %s.', $destination->shown(), $source),
        };
    }
}
