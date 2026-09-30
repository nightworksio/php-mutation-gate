<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function basename;
use function dirname;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\Config\Chosen;
use NightWorksIO\MutationGate\Cli\Config\Effective;
use NightWorksIO\MutationGate\Cli\Config\Formats;
use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\DeclaredTree;
use NightWorksIO\MutationGate\Core\Config\Floors;
use NightWorksIO\MutationGate\Core\Config\Format;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\Setup;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\GitIgnore;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Workspace;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Extension\Extensions;

use function sprintf;
use function str_ends_with;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `init`: a config file holding exactly what zero-config found, so adopting
 * one changes nothing until somebody edits it (ADR-0002): the preset, the
 * runner and the trees. It also keeps `.mutation-gate/` out of git.
 */
final readonly class Init
{
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
                $given = CommandLine::from($input);
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
        $config = self::config($extensions, $settings);
        $text = $config instanceof Layer
            ? $formats->file(
                $config,
                $destination->format(),
                ConfigFile::at($destination->file(), Path::of($project)),
            )
            : $config;

        return is_string($text) ? self::write($project, $destination, $text) : $text;
    }

    /** The preset, the runner and the trees zero-config found, as a config, or why there is none to write. */
    private static function config(
        Extensions $extensions,
        Settings|Invalid|CannotJudge $settings,
    ): Layer|Invalid|CannotJudge {
        if (! $settings instanceof Settings) {
            return $settings;
        }

        $source = new Chosen($extensions)->treeSource($settings->treeSource());
        $trees = $source instanceof Invalid || $source instanceof CannotJudge ? $source : $source->trees();

        return $trees instanceof Trees ? self::found($settings, $trees) : $trees;
    }

    private static function found(Settings $settings, Trees $trees): Layer
    {
        $declared = [];

        foreach ($trees as $tree) {
            $declared[] = self::tree($tree);
        }

        return Layer::of(
            Setup::of(
                presets: $settings->presets(),
                runner: Choice::of($settings->runner()->choice()->use(), Json::object()),
            ),
            Floors::of(trees: Listed::of(...$declared)),
        );
    }

    /** A tree as `trees` lists it, with the floor of 0 an exclusion gives it. */
    private static function tree(Tree $tree): DeclaredTree
    {
        $declared = $tree->declared();

        return DeclaredTree::of(
            $tree->path(),
            $declared instanceof Exempt ? $declared : Undeclared::floor(),
            Listed::of(),
        );
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
                self::ignored(),
            ),
            default => sprintf('Wrote %s with what zero-config found.', $destination->shown()),
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
