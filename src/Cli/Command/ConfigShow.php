<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function is_string;

use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\Config\Effective;
use NightWorksIO\MutationGate\Cli\Config\Formats;
use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\ContextChain;
use NightWorksIO\MutationGate\Core\Config\Format;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Verb;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `config:show`: the effective config, every setting with its value, after
 * presets, the config file and the command line (ADR-0002), in any of the
 * four formats, with each context block as the layers write it; or, with
 * `--resolved`, the config of a run in the contexts it is in, whose blocks
 * are laid and dropped, naming those contexts on standard error (ADR-0070).
 *
 * @internal
 */
final readonly class ConfigShow
{
    private const string RESOLVED = 'resolved';

    private const string CONTEXTS = 'Resolved for the contexts %s.';

    public static function command(Effective $effective, Formats $formats): Command
    {
        return new Command(Verb::ConfigShow->value)
            ->setDescription('Print the effective config')
            ->addOption(
                FormOption::FORMAT,
                mode: InputOption::VALUE_REQUIRED,
                description: Format::words(),
                default: Format::Json->value,
            )
            ->addOption(
                self::RESOLVED,
                mode: InputOption::VALUE_NONE,
                description: 'Lay the blocks of the contexts the run is in, or those --context names',
            )
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($effective, $formats): int {
                $given = CommandLine::from($input);
                $shown = $input->getOption(self::RESOLVED) === true
                    ? self::resolved($effective, $formats, $input, $given, Aside::of($output))
                    : self::shown($formats, $input, $effective->asWritten($given));

                if (! is_string($shown)) {
                    return Failed::because($output, $shown);
                }

                $output->write($shown, options: OutputInterface::OUTPUT_RAW);

                return ExitCode::Passed->value;
            });
    }

    /** The config of a run in the contexts it is in, naming them aside; or why it cannot be printed. */
    private static function resolved(
        Effective $effective,
        Formats $formats,
        InputInterface $input,
        CommandLine $given,
        OutputInterface $aside,
    ): string|Invalid|CannotJudge {
        $contexts = $effective->contexts($given);
        if (! $contexts instanceof ContextChain) {
            return $contexts;
        }

        $shown = self::shown($formats, $input, $effective->settings($given));

        if (is_string($shown)) {
            $aside->writeln(sprintf(self::CONTEXTS, $contexts->spelt()));
        }

        return $shown;
    }

    private static function shown(
        Formats $formats,
        InputInterface $input,
        Settings|Invalid|CannotJudge $settings,
    ): string|Invalid|CannotJudge {
        $chosen = Formats::chosen(CommandText::option($input, FormOption::FORMAT));

        return match (true) {
            ! $settings instanceof Settings => $settings,
            $chosen instanceof CannotJudge => $chosen,
            default => $formats->render($settings->effective(), $chosen, ProjectRoot::origin()),
        };
    }
}
