<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function is_string;

use NightWorksIO\MutationGate\Cli\Config\Effective;
use NightWorksIO\MutationGate\Cli\Config\Formats;
use NightWorksIO\MutationGate\Cli\Config\Given;
use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Document;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `config:show`: the effective config, every setting with its value, after
 * presets, the config file and the command line (ADR-0002), in any of the
 * four formats.
 */
final readonly class ConfigShow
{
    public static function command(Effective $effective, Formats $formats): Command
    {
        return new Command('config:show')
            ->setDescription('Print the effective config')
            ->addOption(
                'format',
                mode: InputOption::VALUE_REQUIRED,
                description: 'php, json, yaml or neon',
                default: 'json',
            )
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($effective, $formats): int {
                $shown = self::shown($effective, $formats, $input);

                if (! is_string($shown)) {
                    return Failed::because($output, $shown);
                }

                $output->write($shown, options: OutputInterface::OUTPUT_RAW);

                return ExitCode::Passed->value;
            });
    }

    private static function shown(
        Effective $effective,
        Formats $formats,
        InputInterface $input,
    ): string|Invalid|CannotJudge {
        $settings = $effective->settings(Given::from($input));
        $format = $input->getOption('format');

        if ($settings instanceof Invalid || $settings instanceof CannotJudge) {
            return $settings;
        }

        $document = Document::ofJson($settings->effective());

        return $document instanceof Document
            ? $formats->render($document, is_string($format) ? $format : '')
            : $document;
    }
}
