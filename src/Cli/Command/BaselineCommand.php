<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Baselines;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\Measured;
use NightWorksIO\MutationGate\Cli\Flow\MeasuredFloors;
use NightWorksIO\MutationGate\Cli\Flow\Raising;
use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\CannotJudge;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mutation-gate baseline` shows every tree's floor beside its last measured
 * score, the one the newest result of each of its units adds up to.
 * `baseline --write` sets every improved floor, and every missing one, to
 * that score; it never lowers one.
 */
final readonly class BaselineCommand
{
    public static function command(Composition $composition): Command
    {
        return new Command('baseline')
            ->setDescription('Show, or write, floors raised to what was measured')
            ->addOption(FlowOptions::WRITE, mode: InputOption::VALUE_NONE, description: 'Write every improved floor')
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($composition): int {
                $composed = $composition->compose($input);

                if (! $composed instanceof Composed) {
                    return Failed::because($output, $composed);
                }

                $baselines = new Baselines($composed->adapters, $composed->settings->floors()->baseline());
                $committed = $baselines->committed();

                if ($committed instanceof CannotJudge) {
                    return Failed::because($output, $committed);
                }

                $measured = Measured::of(
                    $composed->adapters,
                    $composed->settings,
                    $committed,
                    $composed->setup->clock->now(),
                );

                return $measured instanceof Measured
                    ? self::said($input, $output, $baselines, $committed, $measured)
                    : Failed::because($output, $measured);
            });
    }

    private static function said(
        InputInterface $input,
        OutputInterface $output,
        Baselines $baselines,
        Baseline $committed,
        Measured $measured,
    ): int {
        $lines = $input->getOption(FlowOptions::WRITE) === true
            ? new Raising($baselines)->raise($committed, $measured->trees(), $measured->security())
            : MeasuredFloors::of($committed, $measured);

        if ($lines instanceof CannotJudge) {
            return Failed::because($output, $lines);
        }

        foreach ($lines as $line) {
            $output->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        return ExitCode::Passed->value;
    }
}
