<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function is_string;

use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\Doctor\Observed;
use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Doctor\Diagnosis;
use NightWorksIO\MutationGate\Core\Doctor\DoctorJson;
use NightWorksIO\MutationGate\Core\Doctor\DoctorText;
use NightWorksIO\MutationGate\Core\Doctor\Output;
use NightWorksIO\MutationGate\Core\Troubleshooting\Guide;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `doctor`: what would fail, or run slowly, before a run does (ADR-0017,
 * decision 9). It reads and never writes, runs none of the project's code,
 * and exits 1 when anything would fail a run.
 */
final readonly class Doctor
{
    private const string UNKNOWN_FORMAT = '--format is %s; doctor writes text or json.';

    public static function command(Observed $observed, Guide $guide): Command
    {
        return new Command('doctor')
            ->setDescription('Say what would fail, or run slowly, before a run does')
            ->addOption(
                'format',
                mode: InputOption::VALUE_REQUIRED,
                description: 'text or json',
                default: Output::Text->value,
            )
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($observed, $guide): int {
                $asked = $input->getOption('format');
                $format = Output::tryFrom(is_string($asked) ? $asked : '');

                if (! $format instanceof Output) {
                    $refused = CannotJudge::because(sprintf(self::UNKNOWN_FORMAT, is_string($asked) ? $asked : ''));

                    return Failed::because($output, $refused);
                }

                $findings = Diagnosis::of($observed->of(CommandLine::from($input)));
                $output->writeln(
                    $format === Output::Json ? DoctorJson::of($findings, $guide) : DoctorText::of($findings, $guide),
                    OutputInterface::OUTPUT_RAW,
                );

                return $findings->failARun() ? ExitCode::Failed->value : ExitCode::Passed->value;
            });
    }
}
