<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function basename;

use DateTimeImmutable;

use function dirname;
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
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Format;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\GitIgnore;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Workspace;
use NightWorksIO\MutationGate\Core\Import\Import;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Extension\Extensions;

use function sprintf;
use function str_ends_with;

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

    public static function command(
        string $project,
        Extensions $extensions,
        Effective $effective,
        Formats $formats,
        DateTimeImmutable $now,
    ): Command {
        return self::formatted(new Command('init'))
            ->setDescription('Write a config holding what zero-config found')
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
            ): int {
                $from = $input->getOption('from');
                $file = $from === false ? NotGiven::value() : InfectionFile::named(is_string($from) ? $from : '');
                $setting = new Setting($project, $extensions, $effective, $formats, $now);

                return self::run($input, $output, $setting, $file);
            });
    }

    /** `import`: `init --from`, with the Infection config as an argument. */
    public static function import(
        string $project,
        Extensions $extensions,
        Effective $effective,
        Formats $formats,
        DateTimeImmutable $now,
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
            ): int {
                $file = $input->getArgument('file');
                $setting = new Setting($project, $extensions, $effective, $formats, $now);

                return self::run($input, $output, $setting, InfectionFile::named(is_string($file) ? $file : ''));
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
    ): int {
        $format = $input->getOption('format');
        $given = CommandLine::from($input);
        $destination = Destination::of($setting->project, $given->config, is_string($format) ? $format : '');
        $file = $from instanceof InfectionFile ? $from->in($setting->project) : NotGiven::value();

        if ($destination instanceof CannotJudge || $file instanceof CannotJudge) {
            return Failed::because($output, $destination instanceof CannotJudge ? $destination : $file);
        }

        $existing = $destination->existing();
        $given = $file instanceof Path ? $given->choosing(Choices::runner()->use()->value()) : $given;
        $settings = $existing instanceof NoConfigFile
            ? $setting->effective->settings($given->withoutConfig())
            : self::refused($existing);
        $written = self::written($setting, $settings, $destination, $file);

        if (! is_string($written)) {
            return Failed::because($output, $written);
        }

        $output->writeln($written, OutputInterface::OUTPUT_RAW);

        return ExitCode::Passed->value;
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
        Settings|Invalid|CannotJudge $settings,
        Destination $destination,
        Path|NotGiven $from,
    ): string|Invalid|CannotJudge {
        $import = self::seeded($setting, $settings, $from);
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
        Settings|Invalid|CannotJudge $settings,
        Path|NotGiven $from,
    ): Import|Invalid|CannotJudge {
        $found = ZeroConfig::layer($setting->extensions, $settings);

        if (! $found instanceof Layer) {
            return $found;
        }

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
        $ignored = $written instanceof CannotJudge ? $written : self::ignore(Directory::at($project));

        return match (true) {
            $ignored instanceof CannotJudge => $ignored,
            $ignored => sprintf(
                'Wrote %s with %s, and added %s to .gitignore.',
                $destination->shown(),
                $source,
                self::ignored(),
            ),
            default => sprintf('Wrote %s with %s.', $destination->shown(), $source),
        };
    }

    /** Whether `.mutation-gate/` had to be added to `.gitignore`, as `init` adds it. */
    private static function ignore(Directory $project): bool|CannotJudge
    {
        $gitignore = $project->read(Path::of(GitIgnore::FILE));
        $text = $gitignore instanceof Contents ? $gitignore->text() : '';

        if ($gitignore instanceof CannotJudge) {
            return $gitignore;
        }

        if (GitIgnore::of($text)->names(Workspace::root())) {
            return false;
        }

        $added = $project->write(
            Path::of(GitIgnore::FILE),
            Contents::of(
                sprintf(
                    '%s%s%s',
                    $text,
                    $text === '' || str_ends_with($text, "\n") ? '' : "\n",
                    sprintf("%s\n", self::ignored()),
                ),
            ),
        );

        return $added instanceof CannotJudge ? $added : true;
    }

    /** The gate's own directory, as `init` adds it to `.gitignore`. */
    private static function ignored(): string
    {
        return sprintf('%s/', Workspace::root()->value());
    }
}
