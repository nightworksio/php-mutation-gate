<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function array_flip;
use function array_key_exists;
use function array_map;
use function basename;
use function count;
use function dirname;
use function explode;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Config\Chosen;
use NightWorksIO\MutationGate\Cli\Config\Effective;
use NightWorksIO\MutationGate\Cli\Config\Formats;
use NightWorksIO\MutationGate\Cli\Config\Given;
use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Definition\Json;
use NightWorksIO\MutationGate\Core\Config\Document;
use NightWorksIO\MutationGate\Core\Config\Format;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Extension\Extensions;

use function sprintf;
use function str_ends_with;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function trim;

/**
 * `init`: a config file holding exactly what zero-config found, so adopting
 * one changes nothing until somebody edits it (ADR-0002): the preset, the
 * runner and the trees. It also keeps `.mutation-gate/` out of git.
 */
final readonly class Init
{
    private const string SCHEMA = 'vendor/nightworksio/mutation-gate/resources/mutation-gate.schema.json';

    /** The gate's own directory, as `.gitignore` names it with or without its slashes. */
    private const string DIRECTORY = '.mutation-gate';

    private const string IGNORED = '.mutation-gate/';

    private const string GITIGNORE = '.gitignore';

    public static function command(
        string $project,
        Extensions $extensions,
        Effective $effective,
        Formats $formats,
    ): Command {
        return new Command('init')
            ->setDescription('Write a config holding what zero-config found')
            ->addOption(
                'format',
                mode: InputOption::VALUE_REQUIRED,
                description: 'php, json, yaml or neon',
                default: Format::Php->value,
            )
            ->setCode(static function (InputInterface $input, OutputInterface $output) use (
                $project,
                $extensions,
                $effective,
                $formats,
            ): int {
                $format = $input->getOption('format');
                $given = Given::from($input);
                $destination = Destination::of($project, $given->config, is_string($format) ? $format : '');

                if ($destination instanceof CannotJudge) {
                    return Failed::because($output, $destination);
                }

                $existing = $destination->existing();
                $settings = $existing instanceof Absent
                    ? $effective->settings($given->withoutConfig())
                    : self::refused($existing);
                $written = self::written($project, $extensions, $settings, $formats, $destination);

                if (! is_string($written)) {
                    return Failed::because($output, $written);
                }

                $output->writeln($written, OutputInterface::OUTPUT_RAW);

                return ExitCode::Passed->value;
            });
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

    /** What was written, said as a sentence, or why nothing was. */
    private static function written(
        string $project,
        Extensions $extensions,
        Settings|Invalid|CannotJudge $settings,
        Formats $formats,
        Destination $destination,
    ): string|Invalid|CannotJudge {
        $config = self::config($extensions, $settings, $destination);
        $text = $config instanceof Document ? $formats->render($config, $destination->format()) : $config;

        return is_string($text) ? self::write($project, $destination, $text) : $text;
    }

    /** The preset, the runner and the trees zero-config found, as a config, or why there is none to write. */
    private static function config(
        Extensions $extensions,
        Settings|Invalid|CannotJudge $settings,
        Destination $destination,
    ): Document|Invalid|CannotJudge {
        if (! $settings instanceof Settings) {
            return $settings;
        }

        $source = new Chosen($extensions)->treeSource($settings->treeSource());
        $trees = $source instanceof Invalid || $source instanceof CannotJudge ? $source : $source->trees();

        return $trees instanceof Trees ? self::document($settings, $trees, $destination->format()) : $trees;
    }

    private static function document(Settings $settings, Trees $trees, Format $format): Document|CannotJudge
    {
        $presets = [...$settings->presets()];
        $config = [
            'preset' => count($presets) === 1 ? $presets[0] : $presets,
            'runner' => $settings->runner()->choice()->use(),
            'trees' => array_map(self::tree(...), [...$trees]),
        ];

        $written = $format === Format::Json ? ['$schema' => self::SCHEMA, ...$config] : $config;

        return Document::ofJson(Json::pretty($written));
    }

    /** @return array<string, mixed> a tree as `trees` lists it, with the floor of 0 an exclusion gives it */
    private static function tree(Tree $tree): array
    {
        $declared = $tree->declared();

        return $declared instanceof Exempt
            ? ['path' => $tree->path()->value(), 'floor' => 0, 'reason' => $declared->reason()]
            : ['path' => $tree->path()->value()];
    }

    /** The config written, said as a sentence. */
    private static function write(string $project, Destination $destination, string $text): string|CannotJudge
    {
        $file = $destination->file()->value();
        $written = Directory::at(dirname($file))->write(Path::of(basename($file)), Contents::of($text));
        $ignored = $written instanceof CannotJudge ? $written : self::ignore(Directory::at($project));

        return match (true) {
            $ignored instanceof CannotJudge => $ignored,
            $ignored => sprintf(
                'Wrote %s with what zero-config found, and added %s to .gitignore.',
                $destination->shown(),
                self::IGNORED,
            ),
            default => sprintf('Wrote %s with what zero-config found.', $destination->shown()),
        };
    }

    /** Whether `.mutation-gate/` had to be added to `.gitignore`, as `init` adds it. */
    private static function ignore(Directory $project): bool|CannotJudge
    {
        $gitignore = $project->read(Path::of(self::GITIGNORE));
        $text = $gitignore instanceof Contents ? $gitignore->text() : '';
        $lines = array_flip(array_map(
            static fn(string $line): string => trim(trim($line), '/'),
            explode("\n", $text),
        ));

        if ($gitignore instanceof CannotJudge) {
            return $gitignore;
        }

        if (array_key_exists(self::DIRECTORY, $lines)) {
            return false;
        }

        $added = $project->write(
            Path::of(self::GITIGNORE),
            Contents::of(
                sprintf(
                    '%s%s%s',
                    $text,
                    $text === '' || str_ends_with($text, "\n") ? '' : "\n",
                    sprintf("%s\n", self::IGNORED),
                ),
            ),
        );

        return $added instanceof CannotJudge ? $added : true;
    }
}
